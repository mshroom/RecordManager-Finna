<?php

/**
 * Lido record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2025.
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
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

use function boolval;
use function count;
use function in_array;
use function intval;
use function sprintf;
use function strlen;

/**
 * Lido record class
 *
 * This is a class for processing LIDO records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Lido extends \RecordManager\Base\Record\Lido
{
    use AuthoritySupportTrait;
    use DateSupportTrait;
    use Feature\MediaTypeTrait;
    use Feature\IndexValueTrait;

    /**
     * Main event name reflecting the terminology in the particular LIDO records.
     *
     * Key is event type, value is priority (smaller more important).
     *
     * @var array
     */
    protected $mainEvents = [
        'suunnittelu' => 0,
        'design' => 0,
        'valmistus' => 1,
        'creation' => 1,
    ];

    /**
     * Place event name reflecting the terminology in the particular LIDO records.
     *
     * Key is event type, value is priority (smaller more important).
     *
     * @var array
     */
    protected $placeEvents = [
        'käyttö' => 0,
        'use' => 0,
    ];

    /**
     * Related work relation types for collections.
     *
     * @var array
     */
    protected $relatedWorkRelationTypes = [
        'Kokoelma', 'kuuluu kokoelmaan', 'kokoelma',
    ];

    /**
     * Related work relation types for collections and other entities
     *
     * @var array
     */
    protected $relatedWorkRelationTypesExtended = [
        'Kokoelma', 'kokoelma', 'kuuluu kokoelmaan', 'Arkisto', 'arkisto',
        'Alakokoelma', 'alakokoelma', 'Erityiskokoelma', 'erityiskokoelma',
        'Hankintaerä', 'hankintaerä',
    ];

    /**
     * Related work relation types for related ISBNs.
     *
     * @var array
     */
    protected $relatedISBNRelationTypes = ['is reproduced in', 'julkaisu'];

    /**
     * Description types to exclude from title
     *
     * @var array
     */
    protected $descriptionTypesExcludedFromTitle = ['provenance', 'provenienssi'];

    /**
     * Title types for preferred titles.
     *
     * @var array
     */
    protected $preferredTitleTypes = ['preferred', 'http://terminology.lido-schema.org/lido00169'];

    /**
     * Repository location types to be included.
     *
     * @var array
     */
    protected $repositoryLocationTypes = ['current location', 'http://terminology.lido-schema.org/lido01018'];

    /**
     * Excluded location appellationValue labels.
     *
     * @var array
     */
    protected $excludedLocationAppellationValueLabels = ['tarkempi paikka'];

    /**
     * Location labels which should be included when getting location information.
     *
     * @var array
     */
    protected $includedLocationLabels = ['prt', 'kiinteistötunnus'];

    /**
     * Location labels which should be excluded when getting location information.
     *
     * @var array
     */
    protected $excludedLocationLabels;

    /**
     * PlaceID source mappings
     *
     * @var array
     */
    protected $placeIDSourceMappings = [
        'vtj' => 'prt',
        'kiinteistörekisteri' => 'kiinteistötunnus',
    ];

    /**
     * Hierarchy fields included in allfields.
     *
     * @var array
     */
    protected $hierarchyFieldsInAllFields = [
        'is_hierarchy_title', 'hierarchy_parent_title', 'hierarchy_top_title', 'title_in_hierarchy',
        'title_in_hierarchy_en_str_mv', 'title_in_hierarchy_fi_str_mv', 'title_in_hierarchy_se_str_mv',
        'title_in_hierarchy_sv_str_mv',
    ];

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils
    ) {
        parent::__construct(
            $config,
            $dataSourceConfig,
            $logger,
            $metadataUtils
        );
        $this->excludedLocationLabels
            = $this->config['LidoRecord']['excluded_location_labels'] ?? [
                'tarkempi paikka',
            ];

        $this->initMediaTypeTrait($config);
        $this->initIndexValueTrait($config);
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = parent::toSolrArray($db);

        $data['allfields'][] = $this->getRecordSourceOrganization();

        // Author facets without roles:
        $data['author_facet']
            = $this->getActors($this->getMainEvents(), null, false);

        // This is just the display measurements! There's also the more granular
        // form, which could be useful for some interesting things eg. sorting by
        // size
        $data['measurements'] = $this->getMeasurements();

        $data['identifier'] = $this->getIdentifier();
        $data['culture'] = $this->getCulture();
        $data['rights'] = $this->getRights();

        // Handle sources that contain multiple organisations properly
        if ($this->getDriverParam('institutionInBuilding', false)) {
            $institutionParts = explode('/', $data['institution']);
            $data['building'] = reset($institutionParts);
        }
        if (
            $data['collection']
            && $this->getDriverParam('collectionInBuilding', false)
        ) {
            if (isset($data['building']) && $data['building']) {
                $data['building'] .= '/' . $data['collection'];
            } else {
                $data['building'] = $data['collection'];
            }
        }

        $data['artist_str_mv'] = $this->getActors('valmistus', 'taiteilija');
        $data['photographer_str_mv'] = $this->getActors('valmistus', 'valokuvaaja');
        $data['finder_str_mv'] = $this->getActors('löytyminen', 'löytäjä');
        $data['manufacturer_str_mv'] = $this->getActors('valmistus', 'valmistaja');
        $data['designer_str_mv'] = $this->getActors('suunnittelu', 'suunnittelija');

        // Keep classification_str_mv for backward-compatibility for now
        $data['classification_txt_mv'] = $data['classification_str_mv']
            = $this->getClassifications();
        $data['exhibition_str_mv'] = $this->getEventNames('näyttely');

        $data['category_str_mv'] = $this->getCategories();

        foreach ($this->getSubjectDateRanges() as $range) {
            if (!isset($data['main_date_str'])) {
                $data['main_date_str'] = $this->metadataUtils
                    ->extractYear($range[0]);
                $data['main_date'] = $this->validateDate($range[0]);
            }
            $data['search_daterange_mv'][] = $this->dateRangeToStr($range);
        }

        $daterange = $this->getDateRange('valmistus');
        if ($daterange) {
            if (!isset($data['main_date_str'])) {
                $data['main_date_str'] = $this->metadataUtils
                    ->extractYear($daterange[0]);
                $data['main_date'] = $this->validateDate($daterange[0]);
            }
            $data['search_daterange_mv'][]
                = $data['creation_daterange'] = $this->dateRangeToStr($daterange);
        } else {
            $dateSources = [
                'suunnittelu' => 'design', 'tuotanto' => 'production',
                'kuvaus' => 'photography',
            ];
            foreach ($dateSources as $dateSource => $field) {
                $daterange = $this->getDateRange($dateSource);
                if ($daterange) {
                    $rangeStr = $this->dateRangeToStr($daterange);
                    $data[$field . '_daterange'] = $rangeStr;
                    if (!isset($data['search_daterange_mv'])) {
                        $data['search_daterange_mv'][] = $rangeStr;
                    }
                    if (!isset($data['main_date_str'])) {
                        $data['main_date_str']
                            = $this->metadataUtils->extractYear($daterange[0]);
                        $data['main_date'] = $this->validateDate($daterange[0]);
                    }
                }
            }
        }
        if ($range = $this->getDateRange('käyttö')) {
            $data['use_daterange'] = $this->dateRangeToStr($range);
        }
        if ($range = $this->getDateRange('löytyminen')) {
            $data['finding_daterange'] = $this->dateRangeToStr($range);
        }

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        if ($this->isOnline()) {
            $data['online_boolean'] = '1';
            $data['online_str_mv'] = $this->source;
            if ($this->isFreeOnline()) {
                $data['free_online_boolean'] = '1';
                $data['free_online_str_mv'] = $this->source;
            }
            if ($this->hasHiResImages()) {
                $data['hires_image_boolean'] = '1';
                $data['hires_image_str_mv'] = $this->source;
            }
        }
        $data['location_geo'] = [
            ...$this->getEventPlaceCoordinates(),
            ...$this->getRepositoryLocationCoordinates(),
        ];
        if (!empty($data['location_geo'])) {
            $data['center_coords']
                = $this->metadataUtils->getCenterCoordinates($data['location_geo']);
        }
        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
            $data['usage_rights_ext_str_mv'] = $rights;
        }

        $data['format_ext_str_mv'] = $this->getObjectWorkTypes();

        // Additional authority ids
        $data['topic_id_str_mv'] = $this->getTopicIDs();
        $data['geographic_id_str_mv'] = $this->getGeographicTopicIDs();
        $data['author2_id_str_mv']
            = $this->addNamespaceToAuthorityIds(
                array_unique(
                    [
                        ...$this->getPrimaryAuthorIds(),
                        ...$this->getSecondaryAuthorIds(),
                    ]
                ),
                'author'
            );
        $data['author2_id_role_str_mv'] = $this->addNamespaceToAuthorityIds($this->getAllAuthorIdsAndRoles(), 'author');
        // Add values with namespaces also to allfields
        $data['allfields'] = [
            ...$data['allfields'],
            ...$data['topic_id_str_mv'],
            ...$data['geographic_id_str_mv'],
            ...$data['author2_id_str_mv'],
        ];
        $data['language'] = $this->getLanguages();
        // do not index online urls as they display extra information in Finna
        $onlineUrls = $this->getOnlineUrls();
        $data['media_type_str_mv'] = $this->createMediaTypeArray($onlineUrls);
        $data['identifier_txtP_mv'] = $this->getOtherIdentifiers();
        $resourceIdentifiers = $this->getResourceIdentifiers();
        $data['file_identifier_str_mv'] = $resourceIdentifiers['fileIds'];
        $data['related_isbn_isn_mv'] = $this->getRelatedISBNs();
        $data['material_str_mv'] = $this->getMaterials();
        // Back-compatibility:
        $data['material'] = $data['material_str_mv'];
        return $data;
    }

    /**
     * Get locations for geocoding
     *
     * Returns an associative array of primary and secondary locations
     *
     * @return array
     */
    public function getLocations()
    {
        $subjectLocations = [];
        foreach ($this->getSubjectNodes() as $subject) {
            foreach ($subject->subjectPlace as $placeNode) {
                if (!empty($placeNode->place->gml)) {
                    return [];
                }
                $hierarchicalLocations = false;
                foreach ($placeNode->place as $place) {
                    if ($result = $this->getHierarchicalLocations($place)) {
                        $hierarchicalLocations = true;
                        foreach ($result as $location) {
                            $subjectLocations[] = implode(', ', $location);
                        }
                    }
                }
                if (!$hierarchicalLocations && !empty($placeNode->displayPlace)) {
                    $subjectLocations = [
                        ...$subjectLocations,
                        ...$this->splitLocation((string)$placeNode->displayPlace),
                    ];
                }
            }
        }

        $subjectLocations = array_map(
            function ($s) {
                return rtrim($s, ',. ');
            },
            $subjectLocations
        );

        $locations = [];
        foreach ([$this->getMainEvents(), $this->getPlaceEvents()] as $event) {
            foreach ($this->getEventNodes($event) as $eventNode) {
                foreach ($eventNode->eventPlace as $placeNode) {
                    if (!empty($placeNode->place->gml)) {
                        return [];
                    }
                    if (empty($placeNode->place) && !empty($placeNode->displayPlace)) {
                        $locations = [
                            ...$subjectLocations,
                            ...$this->splitLocation((string)$placeNode->displayPlace),
                        ];
                        continue;
                    }
                    foreach ($placeNode->place as $place) {
                        if ($result = $this->getHierarchicalLocations($place)) {
                            foreach ($result as $location) {
                                $locations[] = implode(', ', $location);
                            }
                        }
                    }
                }
            }
        }

        $displayLocations = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->repositoryWrap->repositorySet ?? [] as $set
        ) {
            if (empty($set->repositoryLocation)) {
                continue;
            }
            if (!empty($set->repositoryLocation->gml)) {
                return [];
            }
            if ($result = $this->getHierarchicalLocations($set->repositoryLocation)) {
                foreach ($result as $location) {
                    $displayLocations[] = implode(', ', $location);
                }
            }
        }
        $accepted = [];
        foreach ([$locations, $displayLocations] as $results) {
            foreach ($results as $location) {
                if (preg_match_all("/[\pL']+/u", trim($location)) === 1) {
                    foreach ($subjectLocations as $compare) {
                        if (str_starts_with($compare, $location)) {
                            continue 2;
                        }
                    }
                }
                $accepted[] = $location;
            }
        }
        return [
            'primary' => $this->processLocations($subjectLocations),
            'secondary' => $this->processLocations($accepted),
        ];
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $authors = $this->getActors($this->getMainEvents(), null, false);
        return $authors ? $authors[0] : '';
    }

    /**
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        return array_filter(array_unique([...parent::getTopicIDs(), ...$this->getSubjectActorIds()]));
    }

    /**
     * Get all geographic topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawGeographicTopicIds(): array
    {
        return $this->processPlaceIDElements($this->getPlaceIDElements(false, true));
    }

    /**
     * Get primary author identifiers
     *
     * @return array<int, string>
     */
    public function getPrimaryAuthorIds(): array
    {
        $results = [];
        foreach ($this->getEventNodes($this->getMainEvents()) as $eventNode) {
            foreach ($eventNode->eventActor as $actorNode) {
                foreach ($actorNode->actorInRole->actor->actorID ?? [] as $actorId) {
                    if ($id = trim((string)$actorId)) {
                        $results[] = $id;
                    }
                }
            }
        }
        return array_values(array_unique($results));
    }

    /**
     * Get secondary author identifiers
     *
     * @return array<int, string>
     */
    public function getSecondaryAuthorIds(): array
    {
        $results = [];
        foreach ($this->getEventNodes($this->getSecondaryAuthorEvents()) as $eventNode) {
            foreach ($eventNode->eventActor as $actorNode) {
                foreach ($actorNode->actorInRole->actor->actorID ?? [] as $actorId) {
                    if ($id = trim((string)$actorId)) {
                        $results[] = $id;
                    }
                }
            }
        }
        return array_values(array_unique($results));
    }

    /**
     * Get related ISBNs
     *
     * @return array
     */
    public function getRelatedISBNs(): array
    {
        $results = [];
        foreach ($this->getRelatedWorkSetNodes($this->relatedISBNRelationTypes) as $set) {
            foreach ($set->relatedWork->object->objectID ?? [] as $identifier) {
                if ($isbn = $this->checkISBN((string)$identifier)) {
                    // Include ISBNs in original format and in ISBN-13 format
                    $results[] = $isbn;
                    if ($normalized = $this->metadataUtils->normalizeISBN($isbn)) {
                        $results[] = $normalized;
                    }
                }
            }
        }
        return array_unique($results);
    }

    /**
     * Return record title in English
     *
     * @return string
     */
    protected function getTitleEn(): string
    {
        return $this->getTitles('en')['preferred'];
    }

    /**
     * Return record title in Finnish
     *
     * @return string
     */
    protected function getTitleFi(): string
    {
        return $this->getTitles('fi')['preferred'];
    }

    /**
     * Return record title in Northern Sami
     *
     * @return string
     */
    protected function getTitleSe(): string
    {
        return $this->getTitles('se')['preferred'];
    }

    /**
     * Return record title in Swedish
     *
     * @return string
     */
    protected function getTitleSv(): string
    {
        return $this->getTitles('sv')['preferred'];
    }

    /**
     * Get all author ids and roles
     *
     * @return array<string>
     */
    protected function getAllAuthorIdsAndRoles(): array
    {
        $results = [];
        foreach (
            $this->getEventNodes(
                [...$this->getMainEvents(), ...$this->getSecondaryAuthorEvents()]
            ) as $eventNode
        ) {
            foreach ($eventNode->eventActor as $actorNode) {
                $ids = $roles = [];
                foreach ($actorNode->actorInRole->actor->actorID ?? [] as $actorId) {
                    if ($id = trim((string)$actorId)) {
                        $ids[] = $id;
                    }
                }
                foreach ($actorNode->actorInRole->roleActor ?? [] as $roleActor) {
                    if ($role = $this->metadataUtils->normalizeRelator((string)($roleActor->term ?? ''))) {
                        $roles[] = $role;
                    }
                }
                if ($ids && $roles) {
                    foreach ($ids as $id) {
                        foreach ($roles as $role) {
                            $results[] = $this->formatAuthorIdWithRole($id, $role);
                        }
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Get subject actor ids
     *
     * @return array<int, string>
     */
    protected function getSubjectActorIds(): array
    {
        $result = [];
        foreach ($this->getSubjectNodes() as $subject) {
            foreach ($subject->subjectActor as $subjectActor) {
                foreach ($subjectActor->actor->actorID ?? [] as $actorID) {
                    if ($id = trim((string)$actorID)) {
                        $result[] = $id;
                    }
                }
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * Process an array of placeID elements
     *
     * @param array $ids PlaceID elements
     *
     * @return array
     */
    protected function processPlaceIDElements(array $ids): array
    {
        $result = [];
        foreach ($ids as $placeID) {
            if (!($id = trim((string)$placeID))) {
                continue;
            }
            if (preg_match('/^https?:/', $id)) {
                $result[] = $id;
                continue;
            }
            if ($type = trim((string)($placeID['type'] ?? ''))) {
                if ($source = trim((string)($placeID->attributes()->source ?? ''))) {
                    $type = $this->placeIDSourceMappings[mb_strtolower($source, 'UTF-8')] ?? $source;
                }
                $result[] = "($type)$id";
            }
        }
        return $result;
    }

    /**
     * Get all the placeID elements
     *
     * @param bool $allEvents                    Return all events for event places
     * @param bool $excludePlacesWithCoordinates Exclude places that contain coordinates
     *
     * @return array
     */
    protected function getPlaceIDElements(bool $allEvents = false, bool $excludePlacesWithCoordinates = false): array
    {
        $result = [];
        foreach ($this->getEventNodes($allEvents ? null : $this->getPlaceEvents()) as $eventNode) {
            foreach ($eventNode->eventPlace as $eventPlace) {
                if (!$excludePlacesWithCoordinates || empty($eventPlace->place->gml)) {
                    foreach ($eventPlace->place->placeID ?? [] as $placeID) {
                        $result[] = $placeID;
                    }
                }
            }
        }
        foreach ($this->getSubjectNodes() as $subject) {
            foreach ($subject->subjectPlace as $subjectPlace) {
                if (!$excludePlacesWithCoordinates || empty($subjectPlace->place->gml)) {
                    foreach ($subjectPlace->place->placeID ?? [] as $placeID) {
                        $result[] = $placeID;
                    }
                }
            }
        }
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap->repositoryWrap->repositorySet
            ?? [] as $set
        ) {
            if (!$excludePlacesWithCoordinates || empty($set->repositoryLocation->gml)) {
                foreach ($set->repositoryLocation->placeID ?? [] as $placeID) {
                    $attr = $placeID->attributes();
                    if (
                        $allEvents || in_array($attr->type, $this->includedLocationLabels)
                        || in_array($attr->source, $this->includedLocationLabels)
                        || ($attr->type == 'URI' && $attr->source == 'YSO')
                    ) {
                        $result[] = $placeID;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Get hierarchical locations as a multidimensional array.
     *
     * @param \SimpleXMLElement $elem Element to check for locations.
     *
     * @return array<int, array>
     */
    protected function getHierarchicalLocations(\SimpleXMLElement $elem): array
    {
        $results = [];
        $currentElements = [$elem];
        do {
            $current = array_shift($currentElements);

            if (!empty($current->namePlaceSet->appellationValue)) {
                // There can be multiple appellationValues in element, meaning multiple streets etc
                $values = [];
                $label = '';
                foreach ($current->namePlaceSet as $name) {
                    foreach ($name->appellationValue as $elemValue) {
                        // We can assume that the label is same in each of different
                        // appellationvalues under same parent
                        // If this is not the case, then things are not going ok
                        $currentLabel = trim((string)$elemValue->attributes()->label);
                        if (in_array(strtolower($currentLabel), $this->excludedLocationLabels)) {
                            // Locations with labels like "Torin kulman takaosa" should be skipped
                            continue;
                        }

                        if ($label && $label !== $currentLabel) {
                            // There seems to be different types of appellationValues so skip the new ones
                            continue;
                        }
                        if (!$label) {
                            $label = $currentLabel;
                        }
                        $values[] = trim((string)$elemValue);
                    }
                }
                // If label is empty, use any placeClassification instead
                if (!$label && !empty($current->placeClassification)) {
                    $label = trim((string)$current->placeClassification);
                }

                // If label is still empty and we have multiple values, then only take the first one into account.
                if (!$label && count($values) > 1) {
                    $values = [array_shift($values)];
                }
                // Check do we create new elements into results or append current value into old results.
                $newEntries = !$results;
                foreach ($values as $value) {
                    if ($newEntries) {
                        $results[] = [$value];
                    } else {
                        foreach ($results as &$result) {
                            $result[] = $value;
                        }
                        unset($result);
                    }
                }
            }
            // Check for any other partOfPlaces
            if (!empty($current->partOfPlace)) {
                foreach ($current->partOfPlace as $place) {
                    $currentElements[] = $place;
                }
            }
        } while (count($currentElements) > 0);
        return $results;
    }

    /**
     * Get repository location coordinates
     *
     * @return array<int, string>
     */
    protected function getRepositoryLocationCoordinates(): array
    {
        $results = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->repositoryWrap->repositorySet ?? [] as $set
        ) {
            if (empty($set->repositoryLocation->gml)) {
                continue;
            }
            if ($wkt = $this->convertGmlToWkt($set->repositoryLocation->gml)) {
                $results[] = $wkt;
            }
        }

        return $results;
    }

    /**
     * Get resource identifiers, used for identifier_txtP_mv and file_identifier_string_mv
     *
     * @return array<string,array> [ids, fileIds]
     */
    protected function getResourceIdentifiers(): array
    {
        $cacheKey = __FUNCTION__;
        if ($this->resultCache[$cacheKey] ?? false) {
            return $this->resultCache[$cacheKey];
        }
        $ids = [];
        $fileIds = [];
        foreach ($this->getResourceSetNodes() as $node) {
            if ($resourceID = trim((string)$node->resourceID)) {
                $ids[] = $resourceID;
                $fileIds[] = $resourceID;
            }
            $description = $node->resourceDescription;
            if ($description && 'displayLink' === trim((string)$description->attributes()->type)) {
                $fileIds[] = trim((string)$description);
            }
        }
        return $this->resultCache[$cacheKey] = compact('ids', 'fileIds');
    }

    /**
     * Split a location found from displayPlace element. Excludes all values
     * found after splitting which has redundancy or has only a single value.
     * Splitting is done from characters ; or /.
     *
     * @param string $location Location to split
     *
     * @return array<int, string>
     */
    protected function splitLocation(string $location): array
    {
        $splitted = preg_split(
            '/[\/;]/',
            $location
        );
        $results = [];
        // Some locations might have redundancy, which causes problems.
        // Try to detect them and discard them from the results
        foreach ($splitted as $value) {
            if ($value = trim($value)) {
                $locationParts = explode(' ', $value);
                array_walk($locationParts, function (&$part): void {
                    $part = trim($part, ', ');
                });
                // If there is only one unique name then it can be really difficult
                // to really determine where it should be located i.e Pohja or i.e lakes
                // so in this case, skip the result
                if (count(array_unique($locationParts)) === 1) {
                    continue;
                }
                $results[] = $value;
            }
        }
        return $results;
    }

    /**
     * Process an array of locations
     *
     * @param array $locations Location strings
     *
     * @return array
     */
    protected function processLocations($locations)
    {
        $result = [];
        // Try to split address lists like "Helsinki, Kalevankatu 17, 19" to separate
        // entries
        foreach ($locations as $location) {
            if (
                preg_match('/(.+?) \d+, *\d+/', $location, $bodyMatches)
                && preg_match_all('/ (\d+)(,|$)/', $location, $matches)
            ) {
                $body = $bodyMatches[1];
                foreach ($matches[1] as $match) {
                    $result[] = "$body $match";
                }
            } else {
                $result[] = $location;
            }
        }
        // Try to add versions with additional notes like
        // "Helsinki Uudenmaankatu 31, katurakennus" removed, but avoid changing e.g.
        // "Uusimaa, Helsinki, Malmi"
        foreach ($result as $item) {
            if (
                preg_match_all("/[\pL']+/u", trim($item)) > 2
                && substr_count($item, ',') == 1
                && preg_match('/(.*[^\s]+\s+\d+),/', $item, $matches)
            ) {
                $result[] = $matches[1];
            }
        }

        // Remove stuff in parenthesis
        $result = array_filter($result);
        $result = array_map(
            function ($s) {
                $s = preg_replace('/\(.*/', '', $s);
                return trim($this->metadataUtils->stripTrailingPunctuation($s));
            },
            $result
        );

        return array_values(array_unique($result));
    }

    /**
     * Try to split logical sublocation parts
     *
     * @param string $mainPlace   Main location
     * @param string $subLocation Sublocation(s)
     *
     * @return array<int, string>
     */
    protected function splitAddresses($mainPlace, $subLocation)
    {
        $locations = [];
        if (preg_match('/[^\s]+(\,(?!\s*\d)|\.|\s*\&)\s+[^\s]+/', $subLocation)) {
            foreach (preg_split('/(\,(?!\s*\d)|\.|\s*\&)\s+/', $subLocation) as $sub) {
                $locations[] = "$mainPlace $sub";
            }
        } else {
            $locations[] = "$mainPlace $subLocation";
        }
        return $locations;
    }

    /**
     * Return usage rights if any
     *
     * @return array<int, string> ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        $result = [];
        foreach ($this->doc->lido->administrativeMetadata->resourceWrap->resourceSet ?? [] as $set) {
            if (isset($set->rightsResource->rightsType->conceptID)) {
                $result[] = (string)$set->rightsResource->rightsType->conceptID;
            } else {
                $result[] = 'restricted';
            }
        }
        return $result;
    }

    /**
     * Return subject identifiers associated with object.
     *
     * @param string[] $exclude List of subject types to exclude (defaults to
     *                          'iconclass' since it doesn't contain human readable
     *                          terms)
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #subjectComplexType
     * @return array
     */
    protected function getTopicIDs($exclude = ['iconclass']): array
    {
        $result = [...parent::getTopicIDs($exclude), ...$this->getSubjectActorIds()];
        return $this->addNamespaceToAuthorityIds($result, 'topic');
    }

    /**
     * Get geographic topic identifiers
     *
     * @return array
     */
    protected function getGeographicTopicIDs()
    {
        $result = $this->processPlaceIDElements($this->getPlaceIDElements());
        return $this->addNamespaceToAuthorityIds($result, 'geographic');
    }

    /**
     * Return materials associated with the object. Materials are contained inside
     * events, and the 'valmistus' (creation) event contains all the materials of the
     * object. Either the individual materials are retrieved, or the display
     * materials element is retrieved in case of failure.
     *
     * @param string|array $eventType Event type(s) allowed
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #materialsTechSetComplexType
     * @return array
     */
    protected function getEventMaterials($eventType)
    {
        $materials = parent::getEventMaterials($eventType);

        if (!empty($materials)) {
            return $materials;
        }

        // If there are no individually listed, straightforwardly indexable materials
        // we can use the displayMaterialsTech field, which is usually meant for
        // display only. However, it's possible to extract the different materials
        // from the display field. Some CMS have only one field for materials so this
        // is the only way to index their materials.

        $material = '';
        foreach ($this->getEventNodes($eventType) as $node) {
            if (!empty($node->eventMaterialsTech->displayMaterialsTech)) {
                $material = (string)$node->eventMaterialsTech->displayMaterialsTech;
                break;
            }
        }
        if (empty($material)) {
            return [];
        }

        $exploded = explode(';', str_replace(',', ';', $material));
        $materials = [];
        foreach ($exploded as $explodedMaterial) {
            $materials[] = trim($explodedMaterial);
        }
        return $materials;
    }

    /**
     * Return the object description.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #descriptiveNoteComplexType
     * @return string
     */
    protected function getDescription()
    {
        $descriptions = [];
        $title = $this->getTitle();
        foreach ($this->getObjectDescriptionSetNodes(['provenienssi']) as $set) {
            foreach ($set->descriptiveNoteValue as $descriptiveNoteValue) {
                $descriptions[] = (string)$descriptiveNoteValue;
            }
        }
        if ($descriptions && $title === implode('; ', $descriptions)) {
            // We have the description already in the title, don't repeat
            $descriptions = [];
        }

        // Also read in "description of subject" which contains data suitable for
        // this field
        $title = str_replace([',', ';'], ' ', $title);
        foreach ($this->getSubjectSetNodes() as $set) {
            $subject = $set->displaySubject;
            $label = $subject['label'];
            $nonTitle = trim(str_replace([',', ';'], ' ', (string)$subject)) != $title;
            if ($nonTitle && (null === $label || 'aihe' === mb_strtolower($label, 'UTF-8'))) {
                $descriptions[] = (string)$set->displaySubject;
            }
        }

        return trim(implode(' ', array_unique($descriptions)));
    }

    /**
     * Return subjects associated with object.
     *
     * @param string[] $exclude List of subject types to exclude (defaults to 'aihe'
     *                          and 'iconclass' since they don't contain human
     *                          readable terms)
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #subjectComplexType
     * @return array
     */
    protected function getSubjectTerms($exclude = ['aihe', 'iconclass'])
    {
        $results = [];
        foreach ($this->getSubjectNodes($exclude) as $subject) {
            foreach ($subject->subjectConcept as $concept) {
                foreach ($concept->term as $term) {
                    // Sometimes there are multiple subjects in one element separated with commas
                    foreach (explode(',', (string)$term) as $explodedSubject) {
                        if ($str = trim($explodedSubject)) {
                            $results[] = $str;
                        }
                    }
                }
            }
            // Add subject actors
            foreach ($subject->subjectActor as $actor) {
                foreach ($actor->actor->nameActorSet ?? [] as $name) {
                    foreach ($name->appellationValue as $value) {
                        if ($str = trim((string)$value)) {
                            $results[] = $str;
                        }
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Get the default language used when building the Solr array
     *
     * @return string
     */
    protected function getDefaultLanguage()
    {
        return $this->getDriverParam('defaultDisplayLanguage', 'fi');
    }

    /**
     * Return the date range associated with specified event
     *
     * @param string|array $event Event type(s) allowed (null = all types)
     *
     * @return null|string[] Null if parsing failed, two ISO 8601 dates otherwise
     *
     * @psalm-suppress RedundantCondition
     */
    protected function getDateRange($event = null)
    {
        $startDate = '';
        $endDate = '';
        $displayDate = '';
        $periodName = '';
        foreach ($this->getEventNodes($event) as $eventNode) {
            if (
                !$startDate
                && !empty($eventNode->eventDate->date->earliestDate)
                && !empty($eventNode->eventDate->date->latestDate)
            ) {
                $startDate = (string)$eventNode->eventDate->date->earliestDate;
                $endDate = (string)$eventNode->eventDate->date->latestDate;
            }
            if (!$displayDate && !empty($eventNode->eventDate->displayDate)) {
                $displayDate = (string)$eventNode->eventDate->displayDate;
            }
            if (!$periodName && !empty($eventNode->periodName->term)) {
                $periodName = (string)$eventNode->periodName->term;
            }
        }

        return $this->processDateRangeValues(
            $startDate,
            $endDate,
            $displayDate,
            $periodName
        );
    }

    /**
     * Return the date ranges associated with subjects
     *
     * @return array[] Array of two ISO 8601 dates
     */
    protected function getSubjectDateRanges()
    {
        $ranges = [];
        foreach ($this->getSubjectNodes() as $node) {
            $startDate = '';
            $endDate = '';
            $displayDate = '';
            if (
                !empty($node->subjectDate->date->earliestDate)
                && !empty($node->subjectDate->date->latestDate)
            ) {
                $startDate = (string)$node->subjectDate->date->earliestDate;
                $endDate = (string)$node->subjectDate->date->latestDate;
            }
            if (!empty($node->subjectDate->displayDate)) {
                $displayDate = (string)$node->subjectDate->displayDate;
            }
            $range = $this->processDateRangeValues(
                $startDate,
                $endDate,
                $displayDate,
                ''
            );
            if ($range) {
                $ranges[] = $range;
            }
        }
        return $ranges;
    }

    /**
     * Process extracted date values and create best possible date range
     *
     * @param string $startDate   Start date
     * @param string $endDate     End date
     * @param string $displayDate Display date
     * @param string $periodName  Period name
     *
     * @return null|string[] Null if parsing failed, two ISO 8601 dates otherwise
     */
    protected function processDateRangeValues(
        $startDate,
        $endDate,
        $displayDate,
        $periodName
    ) {
        if ($startDate) {
            if ($endDate < $startDate) {
                $this->logger->logDebug(
                    'Lido',
                    "Invalid date range {$startDate} - {$endDate}, record "
                    . "{$this->source}." . $this->getID(),
                    true
                );
                $endDate = $startDate;
                $this->storeWarning('invalid date range');
            }
            $startDate = $this->completeDate($startDate);
            $endDate = $this->completeDate($endDate, true);
            if ($startDate === null || $endDate === null) {
                return null;
            }

            return [$startDate, $endDate];
        }

        if ($displayDate) {
            return $this->parseLidoDateRange($displayDate);
        }
        if ($periodName) {
            return $this->parseLidoDateRange($periodName);
        }
        return null;
    }

    /**
     * Complete a partial date
     *
     * @param string $date Date string
     * @param bool   $end  Whether $date represents the end of a date range
     *
     * @return null|string
     */
    protected function completeDate($date, $end = false)
    {
        $negative = false;
        if (substr($date, 0, 1) == '-') {
            $negative = true;
            $date = substr($date, 1);
        }

        if (!$end) {
            if (strlen($date) == 1) {
                $date = '000' . $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 2) {
                $date = '00' . $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 3) {
                $date = '0' . $date . '-01-01T00:00:00Z';
            } elseif (strlen($date) == 4) {
                $date .= '-01-01T00:00:00Z';
            } elseif (strlen($date) == 7) {
                $date .= '-01T00:00:00Z';
            } elseif (strlen($date) == 10) {
                $date .= 'T00:00:00Z';
            }
        } else {
            if (strlen($date) == 1) {
                $date = '00' . $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 2) {
                $date = '00' . $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 3) {
                $date = '0' . $date . '-12-31T23:59:59Z';
            } elseif (strlen($date) == 4) {
                $date .= '-12-31T23:59:59Z';
            } elseif (strlen($date) == 7) {
                try {
                    $d = new \DateTime($date . '-01');
                } catch (\Exception $e) {
                    $this->logger->logDebug(
                        'Lido',
                        "Failed to parse date $date, record {$this->source}."
                        . $this->getID(),
                        true
                    );
                    $this->storeWarning('invalid date');
                    return null;
                }
                $date = $d->format('Y-m-t') . 'T23:59:59Z';
            } elseif (strlen($date) == 10) {
                $date .= 'T23:59:59Z';
            }
        }
        if ($negative) {
            $date = "-$date";
        }

        return $date;
    }

    /**
     * Return the event place coordinates associated with specified event
     *
     * @param string|array $event Event type(s) allowed (null = all types)
     *
     * @return array<int, string> WKT
     */
    protected function getEventPlaceCoordinates($event = null)
    {
        $results = [];
        foreach ($this->getEventNodes($event) as $event) {
            foreach ($event->eventPlace as $eventPlace) {
                foreach ($eventPlace->place as $place) {
                    if (!empty($place->gml)) {
                        if ($wkt = $this->convertGmlToWkt($place->gml)) {
                            $results[] = $wkt;
                        }
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Convert SimpleXML GML node to a WKT string
     *
     * This assumes WSG 84
     *
     * @param \SimpleXMLElement $gml GML Node
     *
     * @return string WKT
     */
    protected function convertGmlToWkt($gml)
    {
        if (!empty($gml->Polygon)) {
            if (empty($gml->Polygon->outerBoundaryIs->LinearRing->coordinates)) {
                $this->logger->logDebug(
                    'Lido',
                    'GML Polygon missing outer boundary, record '
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('gml polygon missing outer boundary');
                return '';
            }
            $outerBoundary
                = $this->swapCoordinates(
                    (string)$gml->Polygon->outerBoundaryIs->LinearRing->coordinates
                );
            $innerBoundary
                = !empty($gml->Polygon->innerBoundaryIs->LinearRing->coordinates)
                ? $this->swapCoordinates(
                    (string)$gml->Polygon->innerBoundaryIs->LinearRing->coordinates
                ) : '';

            return $innerBoundary
                ? "POLYGON (($outerBoundary),($innerBoundary))"
                : "POLYGON (($outerBoundary))";
        }

        if (!empty($gml->LineString)) {
            if (empty($gml->LineString->coordinates)) {
                $this->logger->logDebug(
                    'Lido',
                    'GML LineString missing coordinates, record '
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('gml linestring missing coordinates');
                return '';
            }
            $coordinates = $this->swapCoordinates(
                (string)$gml->LineString->coordinates
            );
            return "LINESTRING ($coordinates)";
        }

        if (!empty($gml->Point)) {
            $lat = null;
            $lon = null;
            if (!empty($gml->Point->pos)) {
                $coordinates = trim((string)$gml->Point->pos);
                if (!$coordinates) {
                    $this->logger->logDebug(
                        'Lido',
                        'Empty pos in GML point, record '
                            . "{$this->source}." . $this->getID(),
                        true
                    );
                    $this->storeWarning('empty gml pos in point');
                }
                $latlon = explode(' ', $coordinates, 2);
                if (isset($latlon[1])) {
                    $lat = $latlon[0];
                    $lon = $latlon[1];
                }
            } elseif (isset($gml->Point->coordinates)) {
                $coordinates = trim((string)$gml->Point->coordinates);
                if (!$coordinates) {
                    $this->logger->logDebug(
                        'Lido',
                        'Empty coordinates in GML point, record '
                            . "{$this->source}." . $this->getID(),
                        true
                    );
                    $this->storeWarning('empty gml coordinates in point');
                    return '';
                }
                $latlon = explode(',', $coordinates, 2);
                if (isset($latlon[1])) {
                    $lat = $latlon[0];
                    $lon = $latlon[1];
                }
            }
            if (null === $lat || null === $lon) {
                $this->logger->logDebug(
                    'Lido',
                    'GML Point does not contain pos or coordinates, record '
                        . "{$this->source}." . $this->getID(),
                    true
                );
                $this->storeWarning('gml point missing data');
                return '';
            }
            $lat = trim($lat);
            $lon = trim($lon);
            if ('' === $lat || '' === $lon || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $this->logger->logDebug(
                    'Lido',
                    "Discarding invalid coordinates '$lat,$lon', record "
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('invalid gml coordinates');
                return '';
            }
            return "POINT ($lon $lat)";
        }

        return '';
    }

    /**
     * Convert GML coordinates to WKT coordinates
     *
     * @param string $coordinates GML coordinates
     *
     * @return string WKT coordinates
     */
    protected function swapCoordinates($coordinates)
    {
        $result = [];
        foreach (preg_split('/(?=\/\d)\s(?=\/\d)/', $coordinates) as $coordinate) {
            [$lat, $lon] = explode(',', $coordinate, 2);
            $lat = trim($lat);
            $lon = trim($lon);
            $result[] = "$lon $lat";
        }
        return implode(',', $result);
    }

    /**
     * Attempt to parse a string (in finnish) into a normalized date range.
     *
     * TODO: complicated normalizations like this should preferably reside within
     * their own, separate component which should allow modification of the algorithm
     * by methods other than hard-coding rules into source.
     *
     * @param string $input Date range
     *
     * @return array|null Two ISO 8601 dates or null
     */
    protected function parseLidoDateRange($input)
    {
        $input = trim(strtolower($input));

        $dateMappings = [
            'kivikausi' => ['-8600-01-01T00:00:00Z', '-1501-12-31T23:59:59Z'],
            'pronssikausi'
                => ['-1500-01-01T00:00:00Z', '-0501-12-31T23:59:59Z'],
            'rautakausi' => ['-0500-01-01T00:00:00Z','1299-12-31T23:59:59Z'],
            'keskiaika' => ['1300-01-01T00:00:00Z','1550-12-31T23:59:59Z'],
            'ajoittamaton' => null,
            'tuntematon' => null,
        ];
        $dmyToDmyPeriods = '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)\s*'
            . '-\s*(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/';
        $yearToDmy = '/(\d\d\d\d)\s*'
            . '-\s*(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)/';
        $dmyToYear = '/(\d\d?)\s*.\s*(\d\d?)\s*.\s*(\d\d\d\d)\s*-\s*(\d\d\d\d)/';
        $ymdToYmdPeriods = '/(\d\d\d\d)\s*.\s*(\d\d?)\s*.\s*(\d\d?)\s*'
            . '-\s*(\d\d\d\d)\s*.\s*(\d\d?)\s*.\s*(\d\d?)/';
        $ymdToYmdNoSep = '/(\d\d\d\d)(\d\d?)(\d\d?)\s*-\s*(\d\d\d\d)(\d\d?)(\d\d?)/';
        $ymToYmNoSep = '/(\d\d\d\d)(\d\d?)\s*-\s*(\d\d\d\d)(\d\d?)/';
        $yToYEndish = '/(\d\d\d\d)\s*-\s*(\d\d\d\d)\s*(-luvun|-l)\s+'
            . '(loppupuoli|loppu)/';
        $yStartish = '/(\d?\d?\d\d)\s*-(luvun|luku)\s+'
            . '(alkupuolelta|alkupuoli|alku|alusta)/';
        $midDecade = '/(\d?\d?\d\d)\s*-(luvun|luku)\s+(puoliväli)/';
        $endDecade = '/(\d?\d?\d\d)\s*(-luvun|-l)\s+(loppupuoli|loppu|lopulta|'
            . 'loppupuolelta)/';
        $fromDecade = '/(-?\d?\d?\d\d)\s*-(luku|luvulta|l)/';

        // 1940-1960-luku
        // 1940-1960-l
        // 1940-60-l
        // 1930 - 1970-luku
        // 30-40-luku
        $yToYDecade = '/(\d?\d?\d\d)\s*(-|~)\s*(\d?\d?\d\d)\s*(-luku|-l)?\s*'
            . '(\(?\?\)?)?/';

        $yTextMonth = '/(\d?\d?\d\d)\s+(tammikuu|helmikuu|maaliskuu|huhtikuu|'
            . 'toukokuu|kesäkuu|heinäkuu|elokuu|syyskuu|lokakuu|marraskuu|'
            . 'joulukuu)/';
        $dMYPeriods = '/(\d\d?)\s*\.\s*(\d\d?)\s*\.\s*(\d\d\d\d)/';

        $ekrToEkr = '/(\d?\d?\d\d)\s*ekr.?\s*\-\s*(\d?\d?\d\d)\s*ekr.?/';
        $ekrToJkr = '/(\d?\d?\d\d)\s*ekr.?\s*\-\s*(\d?\d?\d\d)\s*jkr.?/';

        foreach ($dateMappings as $str => $value) {
            if (strstr($input, $str)) {
                return $value;
            }
        }

        $k = [
                'tammikuu' => '01',
                'helmikuu' => '02',
                'maaliskuu' => '03',
                'huhtikuu' => '04',
                'toukokuu' => '05',
                'kesäkuu' => '06',
                'heinäkuu' => '07',
                'elokuu' => '08',
                'syyskuu' => '09',
                'lokakuu' => '10',
                'marraskuu' => '11',
                'joulukuu' => '12',
        ];

        $imprecise = false;

        [$input] = explode(',', $input, 2);

        if (preg_match($dmyToDmyPeriods, $input, $matches)) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[3],
                $matches[2],
                $matches[1]
            );
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[6],
                $matches[5],
                $matches[4]
            );
            $noprocess = true;
        } elseif (preg_match($yearToDmy, $input, $matches)) {
            $startDate = sprintf('%04d-01-01T00:00:00Z', $matches[1]);
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[4],
                $matches[3],
                $matches[2]
            );
            $noprocess = true;
        } elseif (preg_match($dmyToYear, $input, $matches)) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[3],
                $matches[2],
                $matches[1]
            );
            $endDate = sprintf('%04d-12-31T23:59:59Z', $matches[4]);
            $noprocess = true;
        } elseif (preg_match($ymdToYmdPeriods, $input, $matches)) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[1],
                $matches[2],
                $matches[3]
            );
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[4],
                $matches[5],
                $matches[6]
            );
            $noprocess = true;
        } elseif (preg_match($ymdToYmdNoSep, $input, $matches)) {
            $startDate = sprintf(
                '%04d-%02d-%02dT00:00:00Z',
                $matches[1],
                $matches[2],
                $matches[3]
            );
            $endDate = sprintf(
                '%04d-%02d-%02dT23:59:59Z',
                $matches[4],
                $matches[5],
                $matches[6]
            );
            $noprocess = true;
        } elseif (preg_match($ymToYmNoSep, $input, $matches)) {
            $startDate = sprintf('%04d-%02d-01T00:00:00Z', $matches[1], $matches[2]);
            $endDate = sprintf('%04d-%02d-01', $matches[3], $matches[4]);
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID(),
                    true
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            // This one needs to be before the lazy matcher below
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match($yToYEndish, $input, $matches)) {
            $startDate = $matches[1];
            $endDate = intval($matches[2]);
            if ($endDate % 100 == 0) {
                // Century
                $endDate += 99;
            } elseif ($endDate % 10 == 0) {
                // Decade
                $endDate += 9;
            }
        } elseif (preg_match($yToYDecade, $input, $matches)) {
            $startDate = $matches[1];
            $endDate = intval($matches[3]);

            if (isset($matches[4])) {
                if ($endDate % 10 == 0) {
                    $endDate += 9;
                }
            }

            $imprecise = isset($matches[5]);
        } elseif (preg_match($yTextMonth, $input, $matches) > 0) {
            $year = $matches[1];
            $month = $k[$matches[2]];
            $startDate = $year . '-' . $month . '-01T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID(),
                    true
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d)(\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d\d\d)(\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $startDate = $year . '-' . $month . '-01T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID(),
                    true
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match($dMYPeriods, $input, $matches)) {
            $year = $matches[3];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
            $noprocess = true;
        } elseif (preg_match('/(\d\d?)\s*\.\s*(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
                $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Lido',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID(),
                    true
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $noprocess = true;
        } elseif (preg_match($yStartish, $input, $matches)) {
            $year = intval($matches[1]);

            if ($year % 100 == 0) {
                // Century
                $startDate = $year;
                $endDate = $year + 29;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year;
                $endDate = $year + 3;
            } else {
                // Uhh?
                $startDate = $year;
                $endDate = $year;
            }
        } elseif (preg_match($midDecade, $input, $matches)) {
            $year = intval($matches[1]);

            if ($year % 100 == 0) {
                // Century
                $startDate = $year + 29;
                $endDate = $year + 70;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year + 3;
                $endDate = $year + 7;
            } else {
                // Uhh?
                $startDate = $year;
                $endDate = $year;
            }
        } elseif (preg_match($endDecade, $input, $matches)) {
            $year = intval($matches[1]);

            if ($year % 100 == 0) {
                // Century
                $startDate = $year + 70;
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                // Decade
                $startDate = $year + 7;
                $endDate = $year + 9;
            } else {
                $startDate = $year;
                $endDate = $year;
            }
        } elseif (preg_match($fromDecade, $input, $matches)) {
            $year = intval($matches[1]);
            $startDate = $year;

            if ($year % 100 == 0) {
                $endDate = $year + 99;
            } elseif ($year % 10 == 0) {
                $endDate = $year + 9;
            } else {
                $endDate = $year;
            }
        } elseif (preg_match($ekrToEkr, $input, $matches)) {
            $startDate = -(int)$matches[1];
            $endDate = -(int)$matches[2];
        } elseif (preg_match($ekrToJkr, $input, $matches)) {
            $startDate = -(int)$matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d?\d?\d\d) jälkeen/', $input, $matches)) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = intval($year) + 9;
        } elseif (preg_match('/(-?\d\d\d\d)\s*-\s*(-?\d\d\d\d)/', $input, $matches)) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d{1-4})\s+-\s+(-?\d{1-4})/', $input, $matches)) {
            $startDate = $matches[1];
            $endDate = $matches[2];
        } elseif (preg_match('/(-?\d?\d?\d\d)\s*\?/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year;
            $imprecise = true;
        } elseif (preg_match('/(-?\d?\d?\d\d)\b/', $input, $matches) > 0) {
            $year = $matches[1];

            $startDate = $year;
            $endDate = $year;
        } else {
            return null;
        }

        $startDate = (string)$startDate;
        $endDate = (string)$endDate;

        if ($startDate < 0) {
            $startDate = '-' . substr('0000', 0, 5 - strlen($startDate))
                . substr($startDate, 1);
        } elseif ($startDate == 0) {
            $startDate = '0000';
        }

        if ($endDate < 0) {
            $endDate = '-' . substr('0000', 0, 5 - strlen($endDate))
                . substr($endDate, 1);
        } elseif ($endDate == 0) {
            $endDate = '0000';
        }

        switch (strlen($startDate)) {
            case 1:
                $startDate = "000$startDate";
                break;
            case 2:
                $startDate = "19$startDate";
                break;
            case 3:
                $startDate = "0$startDate";
                break;
        }
        switch (strlen($endDate)) {
            case 1:
                $endDate = "000$endDate";
                break;
            case 2:
                // Take into account possible negative sign
                $endDate = substr($startDate, 0, -2) . $endDate;
                break;
            case 3:
                $endDate = "0$endDate";
                break;
        }

        if ($imprecise) {
            // This is way arbitrary, so disabled for now..
            //$startDate -= 2;
            //$endDate += 2;
        }

        if (empty($noprocess)) {
            $startDate .= '-01-01T00:00:00Z';
            $endDate .= '-12-31T23:59:59Z';
        }

        // Trying to index dates into the future? I don't think so...
        $yearNow = date('Y');
        if (substr($startDate, 0, 4) > $yearNow || substr($endDate, 0, 4) > $yearNow) {
            return null;
        }

        $start = $this->metadataUtils->validateISO8601Date($startDate);
        $end = $this->metadataUtils->validateISO8601Date($endDate);
        if ($start === false || $end === false) {
            $this->logger->logDebug(
                'Lido',
                "Invalid date range $startDate - $endDate parsed from "
                    . "'$input', record {$this->source}." . $this->getID(),
                true
            );
            $this->storeWarning('invalid date range');
            if ($start !== false) {
                $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
            } elseif ($end !== false) {
                $startDate = substr($endDate, 0, 4) . '-01-01T00:00:00Z';
            } else {
                return null;
            }
        } elseif ($start > $end) {
            $this->logger->logDebug(
                'Lido',
                "Invalid date range $startDate - $endDate parsed from '$input', "
                    . "record {$this->source}." . $this->getID(),
                true
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [$startDate, $endDate];
    }

    /**
     * Return the classifications.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectClassificationWrap
     * @return array
     */
    protected function getClassifications()
    {
        $results = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectClassificationWrap
            ->classificationWrap->classification ?? [] as $classification
        ) {
            $type = trim((string)$classification->attributes()->type);
            if ('language' !== $type && !empty($classification->term)) {
                foreach ($classification->term as $term) {
                    $results[] = (string)$term;
                }
            }
        }
        return $results;
    }

    /**
     * Return all the names for the specified event type
     *
     * @param string $eventType Event type
     *
     * @return array<int, string>
     */
    protected function getEventNames($eventType)
    {
        $results = [];
        foreach ($this->getEventNodes($eventType) as $event) {
            if (!empty($event->eventName->appellationValue)) {
                $results[] = (string)$event->eventName->appellationValue;
            }
        }
        return $results;
    }

    /**
     * Return the rights holder legal body name.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #legalBodyRefComplexType
     * @return string
     */
    protected function getRightsHolderLegalBodyName()
    {
        foreach ($this->doc->lido->administrativeMetadata->rightsWorkWrap->rightsWorkSet ?? [] as $set) {
            if (!empty($set->rightsHolder->legalBodyName->appellationValue)) {
                return (string)$set->rightsHolder->legalBodyName->appellationValue;
            }
        }
        return '';
    }

    /**
     * Return the organization name in the recordSource element
     *
     * @return string
     */
    protected function getRecordSourceOrganization()
    {
        return (string)($this->doc->lido->administrativeMetadata->recordWrap
            ->recordSource->legalBodyName->appellationValue ?? '');
    }

    /**
     * Return the object types
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectWorkTypeWrap
     * @return array<int, string>
     */
    protected function getObjectWorkTypes()
    {
        $result = [$this->getObjectWorkType()];

        // Check for image links and add a work type for images
        $imageTypes = [
            'Kuva', 'Kuva, Valokuva', 'Valokuva', 'dia', 'kuva', 'negatiivi',
            'photograph', 'valoku', 'valokuva', 'valokuvat',
        ];
        $imageResourceTypes = [
            '', 'image_thumb', 'thumb', 'medium', 'image_large', 'large', 'zoomview',
            'image_master', 'image_original',
        ];
        if (empty(array_intersect($imageTypes, $result))) {
            foreach ($this->getResourceSetNodes() as $set) {
                foreach ($set->resourceRepresentation as $node) {
                    if (!empty($node->linkResource)) {
                        $link = trim((string)$node->linkResource);
                        if (!empty($link)) {
                            $attributes = $node->attributes();
                            $type = (string)$attributes->type;
                            if (in_array($type, $imageResourceTypes)) {
                                $result[] = 'Kuva';
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Return all related works of the object.
     *
     * @param string[] $relatedWorkRelType Which relation types to use
     *
     * @return array<int, string>
     */
    protected function getRelatedWorks($relatedWorkRelType)
    {
        $result = [];
        foreach ($this->getRelatedWorkSetNodes($relatedWorkRelType) as $set) {
            if (!empty($set->relatedWork->displayObject)) {
                $result[] = trim((string)$set->relatedWork->displayObject);
            }
        }

        return $result;
    }

    /**
     * Return the object measurements. Only the display element is used currently
     * until processing more granular data is needed.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #objectMeasurementsSetComplexType
     * @return array<int, string>
     */
    protected function getMeasurements()
    {
        $results = [];
        foreach (
            $this->doc->lido->descriptiveMetadata->objectIdentificationWrap
            ->objectMeasurementsWrap->objectMeasurementsSet ?? [] as $set
        ) {
            $setResults = [];
            foreach ($set->displayObjectMeasurements as $measurements) {
                if ($value = trim((string)$measurements)) {
                    $setResults[] = $value;
                }
            }
            // Use measurementsSet if there's no displayMeasurements:
            if (!$setResults) {
                foreach ($set->objectMeasurements->measurementsSet ?? [] as $measurements) {
                    $parts = [];
                    if ($type = trim((string)($measurements->measurementType ?? ''))) {
                        $parts[] = $type;
                    }
                    if ($val = trim((string)($measurements->measurementValue ?? ''))) {
                        $parts[] = $val;
                    }
                    if ($unit = trim((string)($measurements->measurementUnit ?? ''))) {
                        $parts[] = $unit;
                    }
                    if ($parts) {
                        $setResults[] = implode(' ', $parts);
                    }
                }
            }
            if ($setResults) {
                // Add extents:
                $extents = [];
                foreach ($set->objectMeasurements->extentMeasurements ?? [] as $extent) {
                    if ($value = trim((string)$extent)) {
                        $extents[] = $value;
                    }
                }
                if ($extents) {
                    $extents = implode(', ', $extents);
                    foreach ($setResults as &$current) {
                        if (!str_contains($current, $extents)) {
                            $current .= " ($extents)";
                        }
                    }
                    unset($current);
                }

                $results = [...$results, ...$setResults];
            }
        }
        return $results;
    }

    /**
     * Return all the cultures associated with an object.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #eventComplexType
     * @return array<int, string>
     */
    protected function getCulture()
    {
        $results = [];
        foreach ($this->getEventNodes() as $event) {
            foreach ($event->culture as $culture) {
                if ($value = trim((string)($culture->term ?? ''))) {
                    $results[] = $value;
                }
            }
        }
        return $results;
    }

    /**
     * Return the rights of the object.
     *
     * @link   http://www.lido-schema.org/schema/v1.0/lido-v1.0-schema-listing.html
     * #rightsComplexType
     * @return string
     */
    protected function getRights()
    {
        foreach ($this->getResourceSetNodes() as $set) {
            if ($rights = (string)($set->rightsResource->rightsHolder->legalBodyName->appellationValue ?? '')) {
                return $rights;
            }
        }
        return '';
    }

    /**
     * Check if the record has links to high resolution images
     *
     * @return bool
     */
    protected function hasHiResImages()
    {
        foreach ($this->getResourceSetNodes() as $set) {
            foreach ($set->resourceRepresentation as $node) {
                if (!empty($node->linkResource)) {
                    $link = trim((string)$node->linkResource);
                    if (!empty($link)) {
                        $attributes = $node->attributes();
                        $type = (string)$attributes->type;
                        if ('image_original' === $type || 'image_master' === $type) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get categories
     *
     * @return array<int, string>
     */
    protected function getCategories(): array
    {
        $results = [];
        foreach ($this->doc->lido->category ?? [] as $category) {
            foreach ($category->term ?? [] as $term) {
                $results[] = trim((string)$term);
            }
        }
        return $results;
    }

    /**
     * Get place event types
     *
     * @return array
     */
    protected function getPlaceEvents(): array
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }

        // Include creation event for non-photos:
        $events = $this->placeEvents;
        if ($this->getObjectWorkType() !== 'valokuva') {
            $events['valmistus'] = 999;
            $events['creation'] = 999;
        }

        return $this->resultCache[__METHOD__] = $events;
    }

    /**
     * Get primary authors
     *
     * @return array
     */
    protected function getPrimaryAuthors(): array
    {
        return $this->getActors($this->getMainEvents(), null, true);
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors(): array
    {
        return $this->getActors($this->getSecondaryAuthorEvents(), null, true);
    }

    /**
     * Add hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addHierarchyFields(array &$data): void
    {
        parent::addHierarchyFields($data);
        // Add additional related work titles
        if ($furtherTitles = $this->getRelatedWorks($this->relatedWorkRelationTypesExtended)) {
            // Check that number of indexed parent titles and ids match
            if (
                count((array)($data['hierarchy_parent_title'] ?? []))
                !== count((array)($data['hierarchy_parent_id'] ?? []))
            ) {
                return;
            }
            $data['hierarchy_parent_title'] = [
                ...(array)($data['hierarchy_parent_title'] ?? []),
                ...$furtherTitles,
            ];
            $data['allfields'] = [
                ...(array)($data['allfields'] ?? []),
                ...$furtherTitles,
            ];
        }
    }

    /**
     * Add hierarchy titles.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addHierarchyTitles(array &$data): void
    {
        // Note: title_in_hierarchy is only needed if it differs from title.
        if ($this->getDriverParam('addIdToHierarchyTitle', true)) {
            $data['title_in_hierarchy'] = [trim($this->getIdentifier() . ' ' . $data['title'])];
            foreach (['fi', 'sv', 'en', 'se'] as $language) {
                if ('' !== ($langTitle = $data['title_' . $language . '_txt'] ?? '')) {
                    $data['title_in_hierarchy_' . $language . '_str_mv'] = [
                        trim($this->getIdentifier() . ' ' . $langTitle),
                    ];
                }
            }
        }
    }

    /**
     * Add additional titles.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addAdditionalTitles(array &$data): void
    {
        // Language specific title fields
        $data['title_en_txt'] = $this->getTitleEn();
        $data['title_fi_txt'] = $this->getTitleFi();
        $data['title_se_txt'] = $this->getTitleSe();
        $data['title_sv_txt'] = $this->getTitleSv();
    }

    /**
     * Check if the record is available online
     *
     * @return bool
     */
    protected function isOnline(): bool
    {
        if (null !== ($online = $this->getDriverParam('online', null))) {
            return boolval($online);
        }

        return !empty($this->getUrls());
    }

    /**
     * Check if the record is freely available online
     *
     * @return bool
     */
    protected function isFreeOnline(): bool
    {
        if (null !== ($free = $this->getDriverParam('freeOnline', null))) {
            return boolval($free);
        }
        return $this->getDriverParam('freeOnlineDefault', true);
    }

    /**
     * Get all language codes
     *
     * @return array Language codes
     */
    protected function getLanguages(): array
    {
        $classifications = $this->doc->lido->descriptiveMetadata
                ->objectClassificationWrap->classificationWrap->classification ?? [];
        $result = [];
        foreach ($classifications as $classification) {
            $type = trim((string)$classification->attributes()->type);
            if ('language' === $type) {
                foreach ($classification->term as $lang) {
                    if ($trimmed = trim((string)$lang)) {
                        $result[] = $trimmed;
                    }
                }
            }
        }
        return array_unique($result);
    }

    /**
     * Get online URLs
     *
     * @return array
     */
    protected function getOnlineUrls(): array
    {
        $results = [];
        foreach ($this->getResourceSetNodes() as $set) {
            foreach ($set->resourceRepresentation as $node) {
                $url = trim((string)($node->linkResource ?? ''));
                if (!$url) {
                    continue;
                }
                $mediaType = $this->getLinkMediaType(
                    trim($node->linkResource),
                    trim($node->linkResource->attributes()->formatResource ?? ''),
                    trim($node->attributes()->type ?? '')
                );
                $result = $this->createOnlineURLEntry(
                    url: $url,
                    mediaType: $mediaType,
                    text: $set->resourceDescription ?? '',
                    source: $this->source
                );
                if ($result) {
                    $results[] = $result;
                }
            }
        }
        return $results;
    }

    /**
     * Get other identifiers except ISBN, ISSN and identifiers which are URLs.
     * Contains: workIDs, placeIDs.
     *
     * @see    https://lido-schema.org/schema/v1.1/lido-v1.1.html#placeID
     * @see    https://lido-schema.org/schema/v1.1/lido-v1.1.html#workID
     * @return array
     */
    protected function getOtherIdentifiers(): array
    {
        $filterUrls = function ($el) {
            $trimmed = trim((string)$el);
            return !preg_match('/^https?:/', $el) ? $trimmed : '';
        };
        $identifiers = $this->getIdentifiersByType([], ['issn', 'isbn']);
        $resourceIdentifiers = $this->getResourceIdentifiers();
        $result = array_merge(
            array_map($filterUrls, $identifiers),
            array_map($filterUrls, $this->getPlaceIDElements(true)),
            array_map($filterUrls, $resourceIdentifiers['ids'])
        );
        return array_values(array_filter(array_unique($result)));
    }

    /**
     * Get materials
     *
     * @return array
     */
    protected function getMaterials(): array
    {
        return $this->getEventMaterials($this->getMainEvents());
    }
}
