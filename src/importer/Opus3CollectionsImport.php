<?php
/**
 * This file is part of OPUS. The software OPUS has been originally developed
 * at the University of Stuttgart with funding from the German Research Net,
 * the Federal Department of Higher Education and Research and the Ministry
 * of Science, Research and the Arts of the State of Baden-Wuerttemberg.
 *
 * OPUS 4 is a complete rewrite of the original OPUS software and was developed
 * by the Stuttgart University Library, the Library Service Center
 * Baden-Wuerttemberg, the Cooperative Library Network Berlin-Brandenburg,
 * the Saarland University and State Library, the Saxon State Library -
 * Dresden State and University Library, the Bielefeld University Library and
 * the University Library of Hamburg University of Technology with funding from
 * the German Research Foundation and the European Regional Development Fund.
 *
 * LICENCE
 * OPUS is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the Licence, or any later version.
 * OPUS is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details. You should have received a copy of the GNU General Public License
 * along with OPUS; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * @category    Application
 * @package     Import
 * @author      Oliver Marahrens <o.marahrens@tu-harburg.de>
 * @author      Gunar Maiwald <maiwald@zib.de>
 * @copyright   Copyright (c) 2009, OPUS 4 development team
 * @license     http://www.gnu.org/licenses/gpl.html General Public License
 * @version     $Id$
 */

class Opus3CollectionsImport {

   /**
    * Holds Zend-Configurationfile
    *
    * @var file
    */
    protected $_config = null;

   /**
    * Holds Logger
    *
    * @var file
    */
    protected $_logger = null;

   /**
    * Holds the complete data to import in XML
    *
    * @var xml-structure
    */
    protected $_data = null;

    /**
     * Imports Collection data to Opus4
     *
     * @param Strring $data XML-String with classifications to be imported
     * @return array List of documents that have been imported
     */
    public function __construct($data) {
        $this->_config = Zend_Registry::get('Zend_Config');
        $this->_logger = Zend_Registry::get('Zend_Log');
        $this->_data = $data;
    }
    
    /**
     * Public Method for import of Collections
     *
     * @param void
     * @return void
     *
     */

    public function start() {
        $collRole = Opus_CollectionRole::fetchByName('collections');

        $doclist = $this->_data->getElementsByTagName('table_data');
        foreach ($doclist as $document) {
            if ($document->getAttribute('name') === 'collections') {
                $this->importCollectionsDirectly($document, $collRole);
            }
        }
    }

   /**
     * Imports Collections from Opus3 to Opus4 directly (from DB-table to DB-tables)
     *
     * @param DOMDocument $data XML-Document to be imported
     * @return array List of documents that have been imported
     */
    protected function importCollectionsDirectly($data, $collRole) {
        $mf = $this->_config->migration->mapping->collections;
        $fp = null;
        try {
            $fp = @fopen($mf, 'w');
            if (!$fp) {
                throw new Exception("Could not create '" . $mf . "' for Collections");
            }
        }
        catch (Exception $e){
            $this->_logger->log($e->getMessage(), Zend_Log::ERR);
            return;
        }

        try {
            $collections = $this->transferOpusClassification($data);
            if (count($collections) == 0) {
                throw new Exception("No Collections in XML-Dump");
            }

            // sort by lft-values
            $sortedCollections = $this->msort($collections, 'lft');

            if (count($sortedCollections) == 0) {
                // TODO: Improve error handling in case of empty collections.
                throw new Exception("Sorted collections empty");
            }

            if ($sortedCollections[0]['lft'] != 1) {
                // var_dump($sorted_collections[0]);
                // TODO: Improve error handling in case of wrong left-ids.
                throw new Exception("First left_id is not 1");
            }

            // 1 is used as a predefined key and should not be used again!
            // so lets increment all old IDs by 1
            $previousRightStack = array();
            $previousNodeStack  = array();
            $newCollection     = null;

            foreach ($sortedCollections as $row) {

                //echo ".";
                // case root_node
                if (count($previousRightStack) == 0) {
                    //echo "case 1: id -" . $row['coll_id'] . "-left -" . $row['lft'] . "- right -" .$row['rgt']. "\n";
                    $root = $collRole->getRootCollection();
                    $newCollection = $root->addLastChild();
    //              $collRole->store();

                    array_push($previousNodeStack, $newCollection);
                    array_push($previousRightStack, $row['rgt']);
                }
                else {

                    // Throw elements from stack as long we don't have a
                    // father *or* a brother.
                    do {
                        $previousNode = array_pop($previousNodeStack);
                        $previousRight = array_pop($previousRightStack);

                        $isChild = ($row['rgt'] < $previousRight);
                        $isBrother = ((int) $row['lft'] === (int) $previousRight + 1);
                    } while ( !$isChild && !$isBrother );

                    // same level
                    if ($isBrother) {
                        //echo "case 2: id -" . $row['coll_id'] . "-left -" . $row['lft'] . "- right -" . $row['rgt']
                        //    . "- prevright - " . $previousRight . "-\n";
                        // its a brother of previous node
                        $leftBrother = $previousNode;
                        $newCollection = $leftBrother->addNextSibling();
                        $leftBrother->store();

                        array_push($previousNodeStack, $newCollection);
                        array_push($previousRightStack, $row['rgt']);
                    }
                    else if ($isChild) {
                        // go down one level
                        //echo "case 3: id -" . $row['coll_id'] . "-left -" . $row['lft'] . "- right -" . $row['rgt']
                        // . "- prevright - " . $previousRight . "-\n";
                        // its a child of previous node
                        $father = $previousNode;
                        $newCollection = $father->addLastChild();
                        $father->store();

                        array_push($previousNodeStack, $father);
                        array_push($previousRightStack, $previousRight);

                        array_push($previousNodeStack, $newCollection);
                        array_push($previousRightStack, $row['rgt']);

                    }
                    else {
                        //echo "case 4: id -" . $row['coll_id'] . "-left -" . $row['lft'] . "- right -" . $row['rgt']
                        // . "- prevright - " . $previousRight . "-\n";
                        throw new Exception("Collectionstructure of id " . $row['coll_id'] . " not valid");
                    }
                }

                $newCollection->setVisible(1);
                $newCollection->setName($row['coll_name']);
                $newCollection->store();
                $previousRight = $row['rgt'];

                $this->_logger->log("Collection imported: " . $row['coll_name'], Zend_Log::DEBUG);

                fputs($fp, $row['coll_id'] . ' ' . $newCollection->getId() . "\n");
            }
        }
        catch (Exception $e) {
            $this->_logger->log($e->getMessage(), Zend_Log::ERR);
        }

        fclose($fp);
    }

    /**
     * transfers any OPUS3-conform classification System into an array
     *
     * @param DOMDocument $data XML-Document to be imported
     * @return array List of documents that have been imported
     */
    protected function transferOpusClassification($data) {
        $classification = array();
        $doclist = $data->getElementsByTagName('row');
        $index = 0;
        foreach ($doclist as $document) {
            $classification[$index] = array();
            foreach ($document->getElementsByTagName('field') as $field) {
                $classification[$index][$field->getAttribute('name')] = $field->nodeValue;
            }
            $index++;
        }
        return $classification;
    }

    /**
     * sort multidimensional arrays
     */
    private function msort($array, $id = 'id') {
        $tempArray = array();
        while (count($array) > 0) {
            $lowestId = 0;
            $index=0;
            foreach ($array as $item) {
                if (isset($item[$id]) && $array[$lowestId][$id]) {
                    if ($item[$id] < $array[$lowestId][$id]) {
                        $lowestId = $index;
                    }
                }
                $index++;
            }
            $tempArray[] = $array[$lowestId];
            $array = array_merge(array_slice($array, 0, $lowestId), array_slice($array, $lowestId+1));
        }
        return $tempArray;
    }

}
