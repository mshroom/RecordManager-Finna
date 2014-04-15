<?php
/**
 * Deduplication Handler
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2014.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

/**
 * Deduplication handler
 *
 * This class provides the rules and functions for deduplication of records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class DedupHandler
{
    /**
     * @var MongoDB
     */
    protected $db = null;

    /**
     * @var Logger
     */
    protected $log = null;

    /**
     * @var bool
     */
    protected $verbose = false;

    /**
     * Constructor
     *
     * @param MongoDB $db      Mongo database object
     * @param Logger  $log     Logger object
     * @param boolean $verbose Whether verbose output is enabled
     */
    public function __construct($db, $log, $verbose)
    {
        $this->db = $db;
        $this->log = $log;
        $this->verbose = $verbose;
    }

    /**
     * Verify dedup record consistency
     *
     * @param MongoRecord $dedupRecord Dedup record
     *
     * @return string[] An array with a line per fixed record
     */
    public function checkDedupRecord($dedupRecord)
    {
        $results = array();
        foreach ($dedupRecord['ids'] as $id) {
            $record = $this->db->record->findOne(array('_id' => $id));
            if ($record['deleted'] || !isset($record['dedup_id']) || $record['dedup_id'] != $dedupRecord['_id']) {
                $this->removeFromDedupRecord($dedupRecord['_id'], $id);
                if ($record['deleted']) {
                    $reason = 'record deleted';
                } elseif (!isset($record['dedup_id'])) {
                    $reason = 'record not linked';
                } else {
                    $reason = 'record linked with another dedup record';
                }
                $results[] = "Removed '$id' from dedup record '{$dedupRecord['_id']}' ($reason)";
            }
        }
        return $results;
    }

    /**
     * Update dedup candidate keys for the given record
     *
     * @param object &$record        Database record
     * @param object $metadataRecord Metadata record for the used format
     *
     * @return void
     */
    public function updateDedupCandidateKeys(&$record, $metadataRecord)
    {
        $record['title_keys'] = array(MetadataUtils::createTitleKey($metadataRecord->getTitle(true)));
        if (empty($record['title_keys'])) {
            unset($record['title_keys']);
        }
        $record['isbn_keys'] = $metadataRecord->getISBNs();
        if (empty($record['isbn_keys'])) {
            unset($record['isbn_keys']);
        }
        $record['id_keys'] = $metadataRecord->getUniqueIDs();
        if (empty($record['id_keys'])) {
            unset($record['id_keys']);
        }
    }

    /**
     * Find a single duplicate for the given record and set a dedup key for them
     *
     * @param MongoRecord $record      Database record
     * @param SolrUpdater $solrUpdater Solr updater class
     *
     * @return boolean Whether a duplicate was found
     */
    public function dedupRecord($record, $solrUpdater)
    {
        $startTime = microtime(true);
        if ($this->verbose) {
            echo 'Original ' . $record['_id'] . ":\n" . MetadataUtils::getRecordData($record, true) . "\n";
        }

        $keyArray = isset($record['title_keys']) ? $record['title_keys'] : array();
        $ISBNArray = isset($record['isbn_keys']) ? $record['isbn_keys'] : array();
        $IDArray = isset($record['id_keys']) ? $record['id_keys'] : array();

        $origRecord = null;
        $matchRecord = null;
        $candidateCount = 0;
        foreach (array('isbn_keys' => $ISBNArray, 'id_keys' => $IDArray, 'title_keys' => $keyArray) as $type => $array) {
            foreach ($array as $keyPart) {
                if (!$keyPart) {
                    continue;
                }

                if ($this->verbose) {
                    echo "Search: '$keyPart'\n";
                }
                $candidates = $this->db->record->find(array($type => $keyPart));
                $processed = 0;
                // Go through the candidates, try to match
                $matchRecord = null;
                foreach ($candidates as $candidate) {
                    // Don't dedup with this source or deleted. It's faster to check here than in find!
                    if ($candidate['deleted'] || $candidate['source_id'] == $record['source_id']) {
                        continue;
                    }
                    // Don't bother with id or title dedup if ISBN dedup already failed
                    if ($type != 'isbn_keys') {
                        if (isset($candidate['isbn_keys'])) {
                            $sameKeys = array_intersect($ISBNArray, $candidate['isbn_keys']);
                            if ($sameKeys) {
                                continue;
                            }
                        }
                        if ($type != 'id_keys' && isset($candidate['id_keys'])) {
                            $sameKeys = array_intersect($IDArray, $candidate['id_keys']);
                            if ($sameKeys) {
                                continue;
                            }
                        }
                    }
                    ++$candidateCount;
                    // Verify the candidate has not been deduped with this source yet
                    if (isset($candidate['dedup_id']) && (!isset($record['dedup_id']) || $candidate['dedup_id'] != $record['dedup_id'])) {
                        if ($this->db->record->find(array('dedup_id' => $candidate['dedup_id'], 'source_id' => $record['source_id']))->limit(1)->count() > 0) {
                            if ($this->verbose) {
                                echo "Candidate {$candidate['_id']} already deduplicated\n";
                            }
                            continue;
                        }
                    }

                    if (++$processed > 1000 || (isset($this->tooManyCandidatesKeys["$type=$keyPart"]) && $processed > 100)) {
                        // Too many candidates, give up..
                        $this->log->log('dedupRecord', "Too many candidates for record " . $record['_id'] . " with key '$keyPart'", Logger::DEBUG);
                        if (count($this->tooManyCandidatesKeys) > 2000) {
                            array_shift($this->tooManyCandidatesKeys);
                        }
                        $this->tooManyCandidatesKeys["$type=$keyPart"] = 1;
                        break;
                    }

                    if (!isset($origRecord)) {
                        $origRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id'], $record['source_id']);
                    }
                    if ($this->matchRecords($record, $origRecord, $candidate, $solrUpdater)) {
                        if ($this->verbose && ($processed > 300 || microtime(true) - $startTime > 0.7)) {
                            echo "Found match $type=$keyPart with candidate $processed in " . (microtime(true) - $startTime) . "\n";
                        }
                        $matchRecord = $candidate;
                        break 3;
                    }
                }
                if ($this->verbose && ($processed > 300 || microtime(true) - $startTime > 0.7)) {
                    echo "No match $type=$keyPart with $processed candidates in " . (microtime(true) - $startTime) . "\n";
                }
            }
        }

        if ($this->verbose && microtime(true) - $startTime > 0.2) {
            echo "Candidate search among $candidateCount records (" . ($matchRecord ? 'success' : 'failure') . ") completed in " . (microtime(true) - $startTime) . "\n";
        }

        if ($matchRecord) {
            $this->markDuplicates($record, $matchRecord);

            if ($this->verbose && microtime(true) - $startTime > 0.2) {
                echo "DedupRecord among $candidateCount records (" . ($matchRecord ? 'success' : 'failure') . ") completed in " . (microtime(true) - $startTime) . "\n";
            }

            return true;
        }
        if (isset($record['dedup_id']) || $record['update_needed']) {
            if (isset($record['dedup_id'])) {
                $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
            }
            unset($record['dedup_id']);
            $record['updated'] = new MongoDate();
            $record['update_needed'] = false;
            $this->db->record->save($record);
        }

        if ($this->verbose && microtime(true) - $startTime > 0.2) {
            echo "DedupRecord among $candidateCount records (" . ($matchRecord ? 'success' : 'failure') . ") completed in " . (microtime(true) - $startTime) . "\n";
        }

        return false;
    }

    /**
     * Remove a record from a dedup record
     *
     * @param object $dedupId ObjectID of the dedup record
     * @param string $id      Record ID to remove
     *
     * @return void
     */
    public function removeFromDedupRecord($dedupId, $id)
    {
        $record = $this->db->dedup->findOne(array('_id' => $dedupId));
        if (in_array($id, $record['ids'])) {
            $record['ids'] = array_values(array_diff($record['ids'], array($id)));

            // If there is only one record remaining, remove dedup_id from it too
            if (count($record['ids']) == 1) {
                $otherId = reset($record['ids']);
                $otherRecord = $this->db->record->findOne(array('_id' => $otherId));
                unset($otherRecord['dedup_id']);
                $otherRecord['updated'] = new MongoDate();
                $this->db->record->save($otherRecord);
                $record['ids'] = array();
                $record['deleted'] = true;
            } elseif (count($record['ids']) == 0) {
                // No records remaining => just mark dedup record deleted.
                // This shouldn't happen since dedup record should always contain
                // at least two records
                $record['deleted'] = true;
            }
            $record['changed'] = new MongoDate();
            $this->db->dedup->save($record);

            // Reprocess remaining records as this change may affect their preferred
            // dedup group
            if (!$record['deleted']) {
                if ($this->verbose) {
                    echo "Processing other records in dedup group...\n";
                }
                $records = $this->db->record->find(
                    array(
                        '$in' => $record['ids']
                    )
                );
                foreach ($records as $rec) {
                    $this->dedupRecord($rec);
                }
                if ($this->verbose) {
                    echo "Done processing other records in dedup group\n";
                }
            }
        }
    }

    /**
     * Check if records are duplicate matches
     *
     * @param MongoRecord $record      Mongo record
     * @param object      $origRecord  Metadata record (from $record)
     * @param MongoRecord $candidate   Candidate Mongo record
     * @param SolrUpdater $solrUpdater Solr updater class
     *
     * @return boolean
     */
    protected function matchRecords($record, $origRecord, $candidate, $solrUpdater)
    {
        $cRecord = RecordFactory::createRecord($candidate['format'], MetadataUtils::getRecordData($candidate, true), $candidate['oai_id'], $candidate['source_id']);
        if ($this->verbose) {
            echo "\nCandidate " . $candidate['_id'] . ":\n" . MetadataUtils::getRecordData($candidate, true) . "\n";
        }

        // Check for common ISBN
        $origISBNs = $origRecord->getISBNs();
        $cISBNs = $cRecord->getISBNs();
        $isect = array_intersect($origISBNs, $cISBNs);
        if (!empty($isect)) {
            // Shared ISBN -> match
            if ($this->verbose) {
                echo "++ISBN match:\n";
                print_r($origISBNs);
                print_r($cISBNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true;
        }

        // Check for other common ID (e.g. NBN)
        $origIDs = $origRecord->getUniqueIDs();
        $cIDs = $cRecord->getUniqueIDs();
        $isect = array_intersect($origIDs, $cIDs);
        if (!empty($isect)) {
            // Shared ID -> match
            if ($this->verbose) {
                echo "++ID match:\n";
                print_r($origIDs);
                print_r($cIDs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true;
        }

        $origISSNs = $origRecord->getISSNs();
        $cISSNs = $cRecord->getISSNs();
        $commonISSNs = array_intersect($origISSNs, $cISSNs);
        if (!empty($origISSNs) && !empty($cISSNs) && empty($commonISSNs)) {
            // Both have ISSNs but none match
            if ($this->verbose) {
                echo "++ISSN mismatch:\n";
                print_r($origISSNs);
                print_r($cISSNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return false;
        }

        $origFormat = $origRecord->getFormat();
        $cFormat = $cRecord->getFormat();
        if ($origFormat != $cFormat && $solrUpdater->mapFormat($record['source_id'], $origFormat) != $solrUpdater->mapFormat($candidate['source_id'], $cFormat)) {
            if ($this->verbose) {
                echo "--Format mismatch: $origFormat != $cFormat\n";
            }
            return false;
        }
        $origYear = $origRecord->getPublicationYear();
        $cYear = $cRecord->getPublicationYear();
        if ($origYear && $cYear && $origYear != $cYear) {
            if ($this->verbose) {
                echo "--Year mismatch: $origYear != $cYear\n";
            }
            return false;
        }
        $pages = $origRecord->getPageCount();
        $cPages = $cRecord->getPageCount();
        if ($pages && $cPages && abs($pages-$cPages) > 10) {
            if ($this->verbose) {
                echo "--Pages mismatch ($pages != $cPages)\n";
            }
            return false;
        }

        if ($origRecord->getSeriesISSN() != $cRecord->getSeriesISSN()) {
            return false;
        }
        if ($origRecord->getSeriesNumbering() != $cRecord->getSeriesNumbering()) {
            return false;
        }

        $origTitle = MetadataUtils::normalize($origRecord->getTitle(true));
        $cTitle = MetadataUtils::normalize($cRecord->getTitle(true));
        if (!$origTitle || !$cTitle) {
            // No title match without title...
            if ($this->verbose) {
                echo "No title - no further matching\n";
            }
            return false;
        }
        $lev = levenshtein(substr($origTitle, 0, 255), substr($cTitle, 0, 255));
        $lev = $lev / strlen($origTitle) * 100;
        if ($lev >= 10) {
            if ($this->verbose) {
                echo "--Title lev discard: $lev\nOriginal:  $origTitle\nCandidate: $cTitle\n";
            }
            return false;
        }

        $origAuthor = MetadataUtils::normalize($origRecord->getMainAuthor());
        $cAuthor = MetadataUtils::normalize($cRecord->getMainAuthor());
        $authorLev = 0;
        if ($origAuthor || $cAuthor) {
            if (!$origAuthor || !$cAuthor) {
                if ($this->verbose) {
                    echo "\nAuthor discard:\nOriginal:  $origAuthor\nCandidate: $cAuthor\n";
                }
                return false;
            }
            if (!MetadataUtils::authorMatch($origAuthor, $cAuthor)) {
                $authorLev = levenshtein(substr($origAuthor, 0, 255), substr($cAuthor, 0, 255));
                $authorLev = $authorLev / mb_strlen($origAuthor) * 100;
                if ($authorLev > 20) {
                    if ($this->verbose) {
                        echo "\nAuthor lev discard (lev: $lev, authorLev: $authorLev):\nOriginal:  $origAuthor\nCandidate: $cAuthor\n";
                    }
                    return false;
                }
            }
        }

        if ($this->verbose) {
            echo "\nTitle match (lev: $lev, authorLev: $authorLev):\n";
            echo $origRecord->getFullTitle() . "\n";
            echo "   $origAuthor - $origTitle.\n";
            echo $cRecord->getFullTitle() . "\n";
            echo "   $cAuthor - $cTitle.\n";
        }
        // We have a match!
        return true;
    }

    /**
     * Mark two records as duplicates
     *
     * @param object $rec1 Mongo record for which a duplicate was searched
     * @param object $rec2 Mongo record for the found duplicate
     *
     * @return void
     */
    protected function markDuplicates($rec1, $rec2)
    {
        $setValues = array('updated' => new MongoDate(), 'update_needed' => false);
        if (isset($rec2['dedup_id']) && $rec2['dedup_id']) {
            $this->addToDedupRecord($rec2['dedup_id'], $rec1['_id']);
            if (isset($rec1['dedup_id']) && $rec1['dedup_id'] != $rec2['dedup_id']) {
                $this->removeFromDedupRecord($rec1['dedup_id'], $rec1['_id']);
            }
            $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id'];
        } else {
            if (isset($rec1['dedup_id']) && $rec1['dedup_id']) {
                $this->addToDedupRecord($rec1['dedup_id'], $rec2['_id']);
                $setValues['dedup_id'] = $rec2['dedup_id'] = $rec1['dedup_id'];
            } else {
                $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id'] = $this->createDedupRecord($rec1['_id'], $rec2['_id']);
            }
        }
        if ($this->verbose) {
            echo "Marking {$rec1['_id']} as duplicate with {$rec2['_id']} with dedup id {$rec2['dedup_id']}\n";
        }

        if (!isset($rec1['host_record_id'])) {
            $count = $this->dedupComponentParts($rec1);
            if ($this->verbose && $count > 0) {
                echo "Deduplicated $count component parts for {$rec1['_id']}\n";
            }
        }

        $this->db->record->update(
            array('_id' => array('$in' => array($rec1['_id'], $rec2['_id']))),
            array('$set' => $setValues),
            array('multiple' => true)
        );
    }

    /**
     * Create a new dedup record
     *
     * @param string $id1 ID of first record
     * @param string $id2 ID of second record
     *
     * @return MongoId ID of the dedup record
     */
    protected function createDedupRecord($id1, $id2)
    {
        $record = array(
            '_id' => new MongoId(),
            'changed' => new MongoDate(),
            'deleted' => false,
            'ids' => array(
                $id1,
                $id2
             )
        );
        $this->db->dedup->insert($record);
        return $record['_id'];
    }

    /**
     * Add another record to an existing dedup record
     *
     * @param string $dedupId ID of the dedup record
     * @param string $id      Record ID to add
     *
     * @return void
     */
    protected function addToDedupRecord($dedupId, $id)
    {
        $record = $this->db->dedup->findOne(array('_id' => $dedupId));
        if (!$record) {
            $this->log->log('addToDedupRecord', "Found dangling reference to dedup record $dedupId", Logger::ERROR);
            return;
        }
        if (!in_array($id, $record['ids'])) {
            $record['changed'] = new MongoDate();
            $record['ids'][] = $id;
            $this->db->dedup->save($record);
        }
    }

    /**
     * Deduplicate component parts of a record
     *
     * Component part deduplication is special. It will only go through
     * component parts of other records deduplicated with the host record
     * and stops when it finds a set of component parts that match.
     *
     * @param object $hostRecord Mongo record for the host record
     *
     * @return integer Number of component parts deduplicated
     */
    protected function dedupComponentParts($hostRecord)
    {
        if ($this->verbose) {
            echo "Deduplicating component parts\n";
        }
        if (!$hostRecord['linking_id']) {
            $this->log->log('dedupComponentParts', 'Linking ID missing from record ' . $hostRecord['_id'], Logger::ERROR);
            return 0;
        }
        $components1 = $this->getComponentPartsSorted($hostRecord['source_id'], $hostRecord['linking_id']);
        $component1count = count($components1);

        // Go through all other records with same dedup id and see if their component parts match
        $marked = 0;
        $otherRecords = $this->db->record->find(array('dedup_id' => $hostRecord['dedup_id'], 'deleted' => false));
        foreach ($otherRecords as $otherRecord) {
            if ($otherRecord['source_id'] == $hostRecord['source_id']) {
                continue;
            }
            $components2 = $this->getComponentPartsSorted($otherRecord['source_id'], $otherRecord['linking_id']);
            $component2count = count($components2);

            if ($component1count != $component2count) {
                $allMatch = false;
            } else {
                $allMatch = true;
                $idx = -1;
                foreach ($components1 as $component1) {
                    $component2 = $components2[++$idx];
                    if ($this->verbose) {
                        echo "Comparing {$component1['_id']} with {$component2['_id']}\n";
                    }
                    if ($this->verbose) {
                        echo 'Original ' . $component1['_id'] . ":\n" . MetadataUtils::getRecordData($component1, true) . "\n";
                    }
                    $metadataComponent1 = RecordFactory::createRecord($component1['format'], MetadataUtils::getRecordData($component1, true), $component1['oai_id'], $component1['source_id']);
                    if (!$this->matchRecords($component1, $metadataComponent1, $component2)) {
                        $allMatch = false;
                        break;
                    }
                }
            }

            if ($allMatch) {
                if ($this->verbose) {
                    echo microtime(true) . " All component parts match between {$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
                $idx = -1;
                foreach ($components1 as $component1) {
                    $component2 = $components2[++$idx];
                    $this->markDuplicates($component1, $component2);
                    ++$marked;
                }
                break;
            } else {
                if ($this->verbose) {
                    echo microtime(true) . " Not all component parts match between {$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
            }
        }
        return $marked;
    }

    /**
     * Get component parts in a sorted array
     *
     * @param string $sourceId     Source ID
     * @param string $hostRecordId Host record ID (doesn't include source id)
     *
     * @return array Array of component parts
     */
    protected function getComponentPartsSorted($sourceId, $hostRecordId)
    {
        $componentsIter = $this->db->record->find(array('source_id' => $sourceId, 'host_record_id' => $hostRecordId));
        $components = array();
        foreach ($componentsIter as $component) {
            $components[MetadataUtils::createIdSortKey($component['_id'])] = $component;
        }
        ksort($components);
        return array_values($components);
    }

}
