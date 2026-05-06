<?php

/**
 * EAD 3 Record Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2020.
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
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
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
use function sprintf;

/**
 * EAD 3 Record Class
 *
 * EAD 3 records with Finna specific functionality
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead3 extends \RecordManager\Base\Record\Ead3
{
    use AuthoritySupportTrait;
    use DateSupportTrait;
    use Feature\MediaTypeTrait;
    use Feature\IndexValueTrait;

    // These are always lowercase:
    public const GEOGRAPHIC_SUBJECT_RELATORS = ['aihe', 'alueellinen kattavuus'];
    public const SUBJECT_RELATORS = ['aihe', 'asiasana'];

    public const RELATOR_TIME_INTERVAL = 'suhteen ajallinen kattavuus';

    public const NAME_TYPE_VARIANT = 'varianttinimi';
    public const NAME_TYPE_ALTERNATIVE = 'vaihtehtoinen nimi';
    public const NAME_TYPE_PRIMARY = 'ensisijainen nimi';
    public const NAME_TYPE_OUTDATED = 'vanhentunut nimi';

    public const SUBJECT_ACTOR_ROLES = ['aihe', 'förekommer', 'handlar om', 'refereras till'];
    public const SECONDARY_AUTHOR_ROLES = [
        'brevmottagare', 'donator', 'inlämnare', 'insamlare','keruun järjestäjä', 'kerääjä',
        'luovuttaja', 'vastaanottaja',
    ];

    /**
     * Undefined format type
     *
     * @return string
     */
    protected $undefinedType = 'Määrittämätön';

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
        $doc = $this->doc;

        $first = true;
        foreach ($this->getDateRanges() as $unitDateRange) {
            $range = $unitDateRange['date'];
            $data['search_daterange_mv'][] = $data['unit_daterange']
                = $this->dateRangeToStr($range);

            if ($first) {
                $data['main_date_str'] = $data['era_facet']
                    = $this->metadataUtils->extractYear($range[0]);
                $data['main_date'] = $this->validateDate($range[0]);
                $data = $this->enrichTitlesWithYearRanges($data, $unitDateRange);
                $first = false;
            }
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }

        $data['source_str_mv'] = ($data['institution'] ?? '') ?: $this->source;
        $data['datasource_str_mv'] = $this->source;

        if ($this->isOnline()) {
            $data['online_boolean'] = '1';
            // This is sort of special. Make sure to use source instead
            // of datasource.
            $data['online_str_mv'] = $data['source_str_mv'];

            if ($this->isFreeOnline()) {
                $data['free_online_boolean'] = '1';
                // This is sort of special. Make sure to use source instead
                // of datasource.
                $data['free_online_str_mv'] = $data['source_str_mv'];
            }
        }

        if ($identifier = $this->getUnitId()) {
            $p = strpos($identifier, '/');
            $identifier = $p > 0
                ? substr($identifier, $p + 1)
                : $identifier;
            $data['allfields'][] = $data['identifier'] = $identifier;
        }

        if (isset($doc->did->dimensions)) {
            // display measurements
            $data['measurements'] = (string)$doc->did->dimensions;
        }

        if (isset($doc->did->physdesc)) {
            $material = [];
            foreach ($doc->did->physdesc as $physdesc) {
                if (isset($physdesc->attributes()->label)) {
                    $material[] = (string)$physdesc . ' '
                        . $physdesc->attributes()->label;
                } else {
                    $material[] = (string)$physdesc;
                }
            }
            $data['material'] = $material;
        }

        if (isset($doc->did->userestrict->p)) {
            $data['rights'] = (string)$doc->did->userestrict->p;
        } elseif (isset($doc->did->accessrestrict->p)) {
            $data['rights'] = (string)$doc->did->accessrestrict->p;
        }
        $onlineUrls = $this->getOnlineURLs();
        $data['media_type_str_mv'] = $this->createMediaTypeArray($onlineUrls);
        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
            $data['usage_rights_ext_str_mv'] = $rights;
        }

        $data['author_variant'] = $this->getAuthorVariants();

        $data['author_facet'] = $this->createAuthorFacetArray($data);
        $data['author2_id_str_mv']
                = $this->addNamespaceToAuthorityIds(
                    array_unique(
                        [
                            ...$this->getCorporateAuthorIds(),
                            ...$this->getPrimaryAuthorIds(),
                            ...$this->getSecondaryAuthorIds(),
                        ]
                    ),
                    'author'
                );
        $data['author2_id_role_str_mv']
            = $this->addNamespaceToAuthorityIds(
                $this->getAllAuthorIdsAndRoles(),
                'author'
            );

        foreach ($doc->index->index->indexentry ?? [] as $indexentry) {
            if (isset($indexentry->name->part)) {
                $data['contents'][] = (string)$indexentry->name->part;
            }
        }
        $data['format_ext_str_mv'] = $data['format'];

        $data['topic_id_str_mv'] = $this->getTopicIDs();
        $data['geographic_id_str_mv'] = $this->getGeographicTopicIDs();
        $data['location_geo'] = $this->getGeographicCoordinates();
        if (!empty($data['location_geo'])) {
            $data['center_coords']
                = $this->metadataUtils->getCenterCoordinates($data['location_geo']);
        }
        $resourceIdentifiers = $this->getResourceIdentifiers();
        $data['identifier_txtP_mv'] = $resourceIdentifiers['ids'];
        $data['file_identifier_str_mv'] = $resourceIdentifiers['fileIds'];
        return $data;
    }

    /**
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        $results = $this->getTopicTermsFromNodeWithRelators(
            'subject',
            self::SUBJECT_RELATORS,
            true
        );
        return array_filter(array_unique([...$results, ...$this->getSubjectActorIds()]));
    }

    /**
     * Get all geographic topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawGeographicTopicIds(): array
    {
        return $this->getTopicTermsFromNodeWithRelators(
            'geogname',
            self::GEOGRAPHIC_SUBJECT_RELATORS,
            true
        );
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        // If no genreform, use doc level
        $docLevel = (string)$this->doc->attributes()->level;
        if (!isset($this->doc->controlaccess->genreform)) {
            return $docLevel;
        }
        // For fonds and collections, use doc level as main format
        $level1 = $level2 = null;
        if ($docLevel === 'fonds' || $docLevel === 'collection') {
            $level1 = $docLevel;
        }
        // Check format from genreform
        $defaultFormat = null;
        foreach ($this->doc->controlaccess->genreform as $genreform) {
            $nonLangFormat = null;
            $format = null;
            foreach ($genreform->part as $part) {
                if (null === $nonLangFormat) {
                    $nonLangFormat = (string)$part;
                }
                $attributes = $part->attributes();
                if ((string)($attributes->lang ?? '') === 'fin') {
                    $format = (string)$part;
                    break;
                }
            }
            if (null === $format) {
                $format = $nonLangFormat;
            }
            if (null === $defaultFormat) {
                $defaultFormat = $format;
            }
            if (!$format) {
                continue;
            }
            // Define main and sub format based on encodinganalog attributes
            $attr = $genreform->attributes();
            if (isset($attr->encodinganalog)) {
                $type = (string)$attr->encodinganalog;
                if ($type === 'ahaa:AI08') {
                    if ($level1 === null) {
                        $level1 = $format;
                    } else {
                        $level2 = $format;
                    }
                } elseif ($type === 'ahaa:AI57') {
                    $level2 = $format;
                }
            }
        }
        // For fonds and collections, use genreform as default sub format
        if (($level1 === 'fonds' || $level1 === 'collection') && (null === $level2)) {
            $level2 = $defaultFormat ?? '';
        }
        // For other records, use genreform as default main format
        if (null === $level1) {
            $level1 = $defaultFormat ?? '';
        }
        return $level2 ? "$level1/$level2" : $level1;
    }

    /**
     * Get primary author identifiers
     *
     * @return array<int, string>
     */
    public function getPrimaryAuthorIds(): array
    {
        $results = [];
        foreach ($this->getAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if (($type === 'author' || $type === 'variant') && ($id = $author['id'] ?? '')) {
                    $results[] = $id;
                }
            }
        }
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'author' && ($id = $agent['id'] ?? '')) {
                $results[] = $id;
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
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'author2' && ($id = $agent['id'] ?? '')) {
                $results[] = $id;
            }
        }
        return array_values(array_unique($results));
    }

    /**
     * Get corporate author identifiers
     *
     * @return array<int, string>
     */
    public function getCorporateAuthorIds(): array
    {
        $results = [];
        foreach ($this->getCorporateAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if (($type === 'author' || $type === 'variant') && ($id = $author['id'] ?? '')) {
                    $results[] = $id;
                }
            }
        }
        return array_values(array_unique($results));
    }

    /**
     * Return record title in English
     *
     * @return string
     */
    protected function getTitleEn(): string
    {
        return $this->getTitleByLanguage(false, 'en');
    }

    /**
     * Return record title in Finnish
     *
     * @return string
     */
    protected function getTitleFi(): string
    {
        return $this->getTitleByLanguage(false, 'fi');
    }

    /**
     * Return record title in Northern Sami
     *
     * @return string
     */
    protected function getTitleSe(): string
    {
        return $this->getTitleByLanguage(false, 'se');
    }

    /**
     * Return record title in Swedish
     *
     * @return string
     */
    protected function getTitleSv(): string
    {
        return $this->getTitleByLanguage(false, 'sv');
    }

    /**
     * Get alternate titles
     *
     * @return array
     */
    protected function getAltTitles()
    {
        return array_unique(
            array_filter([$this->getTitleFi(), $this->getTitleSv(), $this->getTitleEn(), $this->getTitleSe()])
        );
    }

    /**
     * Add hierarchy titles.
     *
     * @param array  $data           Reference to the target array
     * @param string $sequenceUnitId Id to be added
     *
     * @return void
     */
    protected function addHierarchyTitles(array &$data, string $sequenceUnitId): void
    {
        // Note: title_in_hierarchy is only needed if it differs from title.
        if ($this->getDriverParam('addIdToHierarchyTitle', true)) {
            $data['title_in_hierarchy'] = [trim("$sequenceUnitId " . $data['title'])];
            foreach (['fi', 'sv', 'en', 'se'] as $language) {
                if ('' !== ($langTitle = $data['title_' . $language . '_txt'] ?? '')) {
                    $data['title_in_hierarchy_' . $language . '_str_mv'] = [trim("$sequenceUnitId " . $langTitle)];
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
     * Enrich titles with year ranges.
     *
     * @param array $data          Record as a solr array
     * @param array $unitDateRange Date range to append as years
     *
     * @return array
     */
    protected function enrichTitlesWithYearRanges(
        array $data,
        array $unitDateRange
    ): array {
        /**
         * Type of enrichment for driver.
         * always = Always add year range to end of a title
         * never = Do not add year range to end of a title
         * no_year_exists = If any year is found from the title then do not add
         * no_match_exists = If any of the given years in unitDateRange are
         * found from the title, then do not add
         * no_matches_exist = If all of the given years in unitDateRange are
         * found from the title, then do not add
         */
        $type = $this->getDriverParam(
            'enrichTitleWithYearRange',
            'no_match_exists'
        );
        if ('never' === $type || $unitDateRange['startDateUnknown']) {
            return $data;
        }
        $range = $unitDateRange['date'];
        $startYear
            = $this->metadataUtils->extractYear($range[0]);
        $endYear = $this->metadataUtils->extractYear($range[1]);
        $yearRange[] = $startYear !== '-9999' ? $startYear : '';
        $yearRange[] = $endYear !== '9999' ? $endYear : '';
        $ndash = html_entity_decode('&#x2013;', ENT_NOQUOTES, 'UTF-8');
        $yearRangeStr = trim(implode($ndash, array_unique($yearRange)));
        if (!$yearRangeStr) {
            return $data;
        }
        // Append with LTR mark first to ensure correct text direction
        $yearRangeStr = "\u{200E} ($yearRangeStr)";

        $appendYear = function (string $title) use ($type, $yearRangeStr, $yearRange): string {
            if ('' === $title) {
                return $title;
            }
            $yearsFound = $this->getYearsFromString($title);
            if ($type === 'always') {
                return $title .= $yearRangeStr;
            } elseif ($type === 'no_year_exists') {
                if (!$yearsFound) {
                    return $title .= $yearRangeStr;
                }
            } elseif ($type === 'no_match_exists') {
                if (!array_intersect($yearRange, $yearsFound)) {
                    return $title .= $yearRangeStr;
                }
            } elseif ($type === 'no_matches_exist') {
                $yearRange = array_filter(array_unique($yearRange));
                if (array_intersect($yearRange, $yearsFound) !== $yearRange) {
                    return $title .= $yearRangeStr;
                }
            }
            return $title;
        };

        foreach (
            [
                'title_full',
                'title_sort',
                'title',
                'title_en_txt',
                'title_fi_txt',
                'title_se_txt',
                'title_sv_txt',
                'title_short',
            ] as $field
        ) {
            $data[$field] = $data[$field] ? $appendYear($data[$field]) : '';
        }
        $data['title_alt'] = array_map(
            $appendYear,
            $data['title_alt'] ?? []
        );
        return $data;
    }

    /**
     * Get unit id
     *
     * @return string
     */
    protected function getUnitId()
    {
        $unitIdLabel = $this->getDriverParam('unitIdLabel', null);
        $firstId = '';
        foreach ($this->doc->did->unitid ?? [] as $i) {
            $attr = $i->attributes();
            if (!isset($attr->identifier)) {
                continue;
            }
            $id = (string)$attr->identifier;
            if (!$firstId) {
                $firstId = $id;
            }
            if (!$unitIdLabel || (string)$attr->label === $unitIdLabel) {
                return $id;
            }
        }
        return $firstId;
    }

    /**
     * Get primary authors
     *
     * @return array<int, string>
     */
    protected function getPrimaryAuthors(): array
    {
        $results = [];
        foreach ($this->getAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'author') {
                    if ($name = $author['name'] ?? '') {
                        $results[] = (string)$name;
                    }
                }
            }
        }
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'author') {
                if ($name = $agent['name'] ?? '') {
                    $results[] = (string)$name;
                }
            }
        }
        return $results;
    }

    /**
     * Get author variant names
     *
     * @return array<string>
     */
    protected function getAuthorVariants(): array
    {
        $results = [];
        foreach ($this->getAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'variant') {
                    if ($name = $author['name'] ?? '') {
                        $results[] = $name;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Get secondary authors
     *
     * @return array<int, string>
     */
    protected function getSecondaryAuthors(): array
    {
        $results = [];
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'author2') {
                if ($name = $agent['name'] ?? '') {
                    $results[] = $name;
                }
            }
        }
        return $results;
    }

    /**
     * Get corporate authors
     *
     * @return array<int, string>
     */
    protected function getCorporateAuthors(): array
    {
        $results = [];
        foreach ($this->getCorporateAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'author') {
                    if ($name = $author['name'] ?? '') {
                        $results[] = $name;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Get all author ids and roles
     *
     * @return array<string>
     */
    protected function getAllAuthorIdsAndRoles(): array
    {
        $results = [];
        foreach ($this->getAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'author' || $type === 'variant') {
                    if ($idWithRole = $author['idWithRole'] ?? '') {
                        $results[] = $idWithRole;
                    }
                }
            }
        }
        foreach ($this->getCorporateAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'author' || $type === 'variant') {
                    if ($idWithRole = $author['idWithRole'] ?? '') {
                        $results[] = $idWithRole;
                    }
                }
            }
        }
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'subject') {
                continue;
            }
            if ($role = $agent['idWithRole'] ?? '') {
                $results[] = $role;
            }
        }
        return $results;
    }

    /**
     * Helper function for getting author details from element
     *
     * @param \SimpleXMLElement $name Name element
     *
     * @return array            array of authors with keys:
     *   'name'       author name
     *   'type'       author type (author, variant or subject)
     *   'id'         author id
     *   'idWithRole' id###role
     */
    protected function getAuthorDetails(
        \SimpleXMLElement $name
    ) {
        $result = [];
        foreach ($name->part as $part) {
            if ($this->isTimeIntervalNode($part)) {
                continue;
            }
            $author = [];
            $relator = trim((string)($name->attributes()->relator ?? ''));
            $identifier = trim((string)($name->attributes()->identifier ?? ''));
            $localtype = mb_strtolower(
                $part->attributes()->localtype ?? '',
                'UTF-8'
            );
            if ($authorName = trim((string)$part)) {
                $author['name'] = $authorName;
                switch ($localtype) {
                    case self::NAME_TYPE_PRIMARY:
                    case '':
                        $author['type'] = 'author';
                        break;
                    case self::NAME_TYPE_VARIANT:
                    case self::NAME_TYPE_ALTERNATIVE:
                    case self::NAME_TYPE_OUTDATED:
                        $author['type'] = 'variant';
                        break;
                }
            }
            if (in_array(mb_strtolower($relator, 'UTF-8'), $this::SUBJECT_ACTOR_ROLES)) {
                $author['type'] = 'subject';
            }
            if ($identifier) {
                $author['id'] = $identifier;
                if ($relator) {
                    $author['idWithRole'] = $this->formatAuthorIdWithRole($identifier, $relator);
                }
            }
            if ($author) {
                $result[] = $author;
            }
        }
        return $result;
    }

    /**
     * Get agents from relations
     *
     * @return array array of agents with keys:
     *   'name'       agent name
     *   'type'       agent type (author, author2 or subject)
     *   'id'         agent id
     *   'idWithRole' id###role
     */
    protected function getAgentsFromRelations(): array
    {
        $result = [];
        foreach ($this->doc->relations->relation ?? [] as $relation) {
            $type = trim((string)($relation->attributes()->relationtype ?? ''));
            if ('cpfrelation' !== $type) {
                continue;
            }
            $agent = [];
            $role = trim((string)($relation->attributes()->arcrole ?? ''));
            $roleLC = mb_strtolower($role, 'UTF-8');
            if ($name = trim((string)($relation->relationentry ?? ''))) {
                $agent['name'] = $name;
            }
            if ($href = trim((string)($relation->attributes()->href ?? ''))) {
                $agent['id'] = $href;
                if ($role) {
                    $agent['idWithRole'] = $this->formatAuthorIdWithRole($href, $role);
                }
            }
            if ($name || $href) {
                if (in_array($roleLC, $this::SUBJECT_ACTOR_ROLES)) {
                    $agent['type'] = 'subject';
                } elseif (in_array($roleLC, $this::SECONDARY_AUTHOR_ROLES)) {
                    $agent['type'] = 'author2';
                } else {
                    $agent['type'] = 'author';
                }
            }
            if ($agent) {
                $result[] = $agent;
            }
        }
        return $result;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        $getRestriction = function ($restrict) {
            if ($href = $restrict['href']) {
                if (
                    preg_match('/^https?:\/\/creativecommons\.org\//', $href)
                    || preg_match('/^https?:\/\/rightsstatements\.org\//', $href)
                ) {
                    return [(string)$href];
                }
            }
            $restrict = (string)$restrict;
            if (strstr($restrict, 'No known copyright restrictions')) {
                return ['No known copyright restrictions'];
            }
            if (
                strncasecmp($restrict, 'CC', 2) === 0
                || strncasecmp($restrict, 'Public', 6) === 0
                || strncasecmp($restrict, 'Julkinen', 8) === 0
            ) {
                return [$restrict];
            }
            return null;
        };

        // Handle each element separately. Any merging as an array is bound to cause
        // problems with element attributes.
        $nonRefRestrict = null;
        foreach ($this->doc->userestrict ?? [] as $userestrict) {
            foreach ($userestrict->p ?? [] as $p) {
                // Use ref as the primary source with contents of the p as a
                // fallback:
                foreach ($p->ref as $ref) {
                    if ($result = $getRestriction($ref)) {
                        return $result;
                    }
                }
                if (null === $nonRefRestrict) {
                    $nonRefRestrict = $getRestriction($p);
                }
            }
        }
        if ($nonRefRestrict) {
            return $nonRefRestrict;
        }
        foreach ($this->doc->accessrestrict ?? [] as $accessrestrict) {
            foreach ($accessrestrict->p ?? [] as $p) {
                if ($result = $getRestriction($p)) {
                    return $result;
                }
            }
        }

        return ['restricted'];
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
        foreach ([$this->doc->did ?? [], $this->doc->did->daoset ?? []] as $root) {
            foreach ($root as $set) {
                foreach ($set->dao as $dao) {
                    if (trim((string)$dao->attributes()->href)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Get URLs
     *
     * @return array
     */
    protected function getOnlineURLs(): array
    {
        $results = [];
        foreach ([$this->doc->did ?? [], $this->doc->did->daoset ?? []] as $root) {
            foreach ($root as $set) {
                foreach ($set->dao as $dao) {
                    $attrs = $dao->attributes();
                    $url = trim((string)$attrs->href);
                    if (empty($url)) {
                        continue;
                    }
                    $result = $this->createOnlineURLEntry(
                        url: $url,
                        text: (string)($attrs->linktitle ?? ''),
                        mediaType: $this->getLinkMediaType(
                            $url,
                            trim((string)$attrs->linkrole),
                        ),
                        source: $this->source
                    );
                    if ($result) {
                        $results[] = $result;
                    }
                }
            }
        }
        return $results;
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
        foreach ($this->doc->accessrestrict ?? [] as $accessrestrict) {
            foreach ($accessrestrict->p ?? [] as $p) {
                if (trim((string)$p) !== '') {
                    return false;
                }
            }
        }
        return $this->getDriverParam('freeOnlineDefault', true);
    }

    /**
     * Return subtitle
     *
     * @return string
     */
    protected function getTitleSub()
    {
        if ($signumLabel = $this->getDriverParam('signumLabel', null)) {
            foreach ($this->doc->did->unitid ?? [] as $id) {
                $attr = $id->attributes();
                if ((string)$attr->label === $signumLabel) {
                    return (string)$id;
                }
            }
        }
        return (string)$this->doc->did->unitid;
    }

    /**
     * Get date ranges
     *
     * @return array
     */
    protected function getDateRanges()
    {
        $result = [];
        foreach ($this->doc->did->unitdatestructured ?? [] as $date) {
            if ($range = $date->daterange) {
                if (isset($range->fromdate) && isset($range->todate)) {
                    // Some data sources have multiple ranges in one daterange
                    // (non-standard presentation), try to handle the case sensibly:
                    $toDate = (string)$range->fromdate;
                    foreach ($range->todate as $to) {
                        $toDate = (string)$to;
                    }
                    $result[] = $this->parseDateRange(
                        (string)$range->fromdate . '/' . $toDate
                    );
                }
            } elseif (isset($date->datesingle)) {
                $year = (string)$date->datesingle;
                $result[] = $this->parseDateRange("{$year}/{$year}");
            }
        }
        if (!$result) {
            $primary = [];
            foreach ($this->doc->did->unitdate ?? [] as $unitdate) {
                $attributes = $unitdate->attributes();
                if (
                    $attributes->label
                    && (string)$attributes->label === 'Ajallinen kattavuus'
                    && $unitdate->attributes()->normal
                ) {
                    $date = $this->parseDateRange(
                        (string)$unitdate->attributes()->normal
                    );
                    if (
                        $date
                        && !$date['startDateUnknown'] && !$date['endDateUnknown']
                    ) {
                        $primary[] = $date;
                        continue;
                    }
                }
                $normal = (string)$unitdate->attributes()->normal;
                if (!empty($normal)) {
                    $parsedDate = $this->parseDateRange($normal);
                    if ($parsedDate) {
                        $result[] = $parsedDate;
                    } else {
                        $normal = strtolower($normal);
                        if (
                            !str_contains($normal, 'xxxx') && !str_contains($normal, 'uuuu')
                            && (str_contains($normal, '-xx-xx') || str_contains($normal, '-uu-uu'))
                        ) {
                            $result[] = $this->parseDateRange("$normal/$normal");
                        }
                    }
                } else {
                    foreach (explode(', ', (string)$unitdate) as $single) {
                        $date = str_replace(['-', '–'], '/', $single);
                        if (!str_contains($date, '/')) {
                            $date = "$date/$date";
                        }
                        $result[] = $this->parseDateRange($date);
                    }
                }
            }
            if ($primary) {
                $result = [...$primary, ...$result];
            }
        }
        return array_filter($result);
    }

    /**
     * Parse date range string
     *
     * @param string $input Date range
     *
     * @return null|array
     */
    protected function parseDateRange($input)
    {
        if (!$input || $input == '-' || !str_contains($input, '/')) {
            return null;
        }

        [$start, $end] = explode('/', $input);

        $parseDate = function (
            string $date,
            string $defaultYear = '0',
            string $defaultMonth = '01',
            ?string $defaultDay = '01',
            string $hour = '00:00:00'
        ) {
            $unknown = false;
            // Set year/month/day to defaults
            $year = str_repeat($defaultYear, 4);
            $month = $defaultMonth;
            $day = $defaultDay;
            $unknownForms = ['uu', 'xx', 'uuuu', 'xxxx'];
            if (!in_array($date, ['open', 'unknown'])) {
                $parts = explode('-', trim($date));
                if (in_array(strtolower($parts[0]), $unknownForms)) {
                    $unknown = true;
                }
                $year = str_ireplace(['u', 'x'], $defaultYear, $parts[0]);

                if (isset($parts[1]) && !in_array(strtolower($parts[1]), $unknownForms)) {
                    $month = $parts[1];
                }

                if (isset($parts[2]) && !in_array(strtolower($parts[2]), $unknownForms)) {
                    $day = $parts[2];
                }
            } else {
                $unknown = true;
            }

            if (null === $day) {
                // Set day to last day of month if default day was not given
                $day = date('t', strtotime("{$year}-{$month}"));
            }

            if (
                !preg_match('/^-?\d{1,4}$/', $year)
                || !preg_match('/^\d{1,2}$/', $month)
                || !preg_match('/^\d{1,2}$/', $day)
            ) {
                return null;
            }

            $date = sprintf(
                '%04d-%02d-%02dT%sZ',
                $year,
                $month,
                $day,
                $hour
            );

            try {
                $d = new \DateTime($date);
            } catch (\Exception $e) {
                return null;
            }

            return compact('date', 'unknown');
        };

        if (null === ($startDate = $parseDate($start))) {
            $this->logger->logDebug(
                'Ead3',
                "Failed to parse startDate $start, record {$this->source}."
                . $this->getID(),
                true
            );
            $this->storeWarning('invalid start date');
            return null;
        }

        if (null === ($endDate = $parseDate($end, '9', '12', null, '23:59:59'))) {
            $this->logger->logDebug(
                'Ead3',
                "Failed to parse endDate $end, record {$this->source}."
                . $this->getID(),
                true
            );
            $this->storeWarning('invalid end date');
            return null;
        }

        $startDateUnknown = $startDate['unknown'];
        $endDateUnknown = $endDate['unknown'];

        $startDate = $startDate['date'];
        $endDate = $endDate['date'];

        if (strtotime($startDate) > strtotime($endDate)) {
            $this->logger->logDebug(
                'Ead3',
                "Invalid date range {$startDate} - {$endDate}, record " .
                "{$this->source}." . $this->getID(),
                true
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [
            'date' => [$startDate, $endDate],
            'startDateUnknown' => $startDateUnknown,
            'endDateUnknown' => $endDateUnknown,
        ];
    }

    /**
     * Return author name with role.
     *
     * @param string $name Name
     * @param string $role Role
     *
     * @return string
     */
    protected function getNameWithRole($name, $role = null)
    {
        return $role
            ? "$name " . strtolower($role)
            : $name;
    }

    /**
     * Helper function for getting controlaccess access elements filtered
     * by relator-attribute.
     *
     * @param string $nodeName    Name of node that contains the topic terms
     * @param array  $relators    Accepted relator-attribute values when relator
     *                            is defined.
     * @param bool   $identifiers Return identifiers instead of labels?
     *
     * @return array<int, string>
     */
    protected function getTopicTermsFromNodeWithRelators(
        $nodeName,
        $relators,
        $identifiers = false
    ) {
        $result = [];
        foreach ($this->doc->controlaccess as $controlaccess) {
            foreach ($controlaccess->{$nodeName} ?? [] as $node) {
                $relator = mb_strtolower(
                    trim((string)($node['relator'] ?? '')),
                    'UTF-8'
                );
                if (!$relator || in_array($relator, $relators)) {
                    if ($identifiers) {
                        if ($id = $node['identifier']) {
                            $result[] = (string)$id;
                        }
                    } else {
                        foreach ($node->part as $part) {
                            if ($value = trim((string)$part)) {
                                $result[] = $value;
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Get topics
     *
     * @return array
     */
    protected function getTopics(): array
    {
        $results = $this->getTopicTermsFromNodeWithRelators(
            'subject',
            self::SUBJECT_RELATORS
        );
        return [...$results, ...$this->getSubjectActors()];
    }

    /**
     * Get subject actors
     *
     * @return array<int, string>
     */
    protected function getSubjectActors()
    {
        $results = [];
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'subject' && ($name = $agent['name'] ?? '')) {
                $results[] = $name;
            }
        }
        foreach ($this->getAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'subject' && ($name = $author['name'] ?? '')) {
                    $results[] = $name;
                }
            }
        }
        foreach ($this->getCorporateAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'subject' && ($name = $author['name'] ?? '')) {
                    $results[] = $name;
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
    protected function getSubjectActorIds()
    {
        $results = [];
        foreach ($this->getAgentsFromRelations() as $agent) {
            $type = $agent['type'] ?? '';
            if ($type === 'subject' && ($id = $agent['id'] ?? '')) {
                $results[] = $id;
            }
        }
        foreach ($this->getAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'subject' && ($id = $author['id'] ?? '')) {
                    $results[] = $id;
                }
            }
        }
        foreach ($this->getCorporateAuthorElements() as $node) {
            foreach ($this->getAuthorDetails($node) as $author) {
                $type = $author['type'] ?? '';
                if ($type === 'subject' && ($id = $author['id'] ?? '')) {
                    $results[] = $id;
                }
            }
        }
        return array_values(array_unique($results));
    }

    /**
     * Get topic identifiers.
     *
     * @return array
     */
    protected function getTopicIDs(): array
    {
        $result = $this->getRawTopicIds();
        return $this->addNamespaceToAuthorityIds($result, 'geographic');
    }

    /**
     * Get geographic topics
     *
     * @return array
     */
    protected function getGeographicTopics()
    {
        return $this->getTopicTermsFromNodeWithRelators(
            'geogname',
            self::GEOGRAPHIC_SUBJECT_RELATORS
        );
    }

    /**
     * Get geographic topics IDs
     *
     * @return array
     */
    protected function getGeographicTopicIDs()
    {
        $result = $this->getRawGeographicTopicIds();
        return $this->addNamespaceToAuthorityIds($result, 'geographic');
    }

    /**
     * Get geographic coordinates
     *
     * @return array<int, string> WKT
     */
    protected function getGeographicCoordinates()
    {
        $result = [];
        foreach ($this->doc->controlaccess as $controlaccess) {
            foreach ($controlaccess->geogname as $geogname) {
                // Don't use these if we have an YSO URI to use with enrichment:
                $id = (string)($geogname->attributes()->identifier ?? '');
                $uri = (string)($geogname->attributes()->uri ?? '');
                if (
                    str_starts_with($id, 'http://www.yso.fi/onto/yso/')
                    || str_starts_with($uri, 'http://www.yso.fi/onto/yso/')
                ) {
                    continue;
                }
                foreach ($geogname->geographiccoordinates as $coordinates) {
                    $system = trim((string)($coordinates->attributes()->coordinatesystem ?? ''));
                    if ($system === 'WGS84') {
                        $trimmedCoordinates = array_map(
                            'trim',
                            explode(',', (string)$coordinates)
                        );
                        if (count($trimmedCoordinates) === 2) {
                            [$lat, $lon] = $trimmedCoordinates;
                            $result[] = "POINT($lon $lat)";
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Get institution
     *
     * @return string
     */
    protected function getInstitution(): string
    {
        $firstResult = $langResult = '';
        foreach ($this->doc->did->repository ?? [] as $repo) {
            $encoding = trim((string)($repo->attributes()->encodinganalog ?? ''));
            foreach ($repo->corpname as $name) {
                $id = trim((string)($name->attributes()->identifier ?? ''));
                if ($id && $encoding === 'ahaa:AI42') {
                    return $id;
                }
                $lang = trim((string)($name->part->attributes()->lang ?? $name->attributes()->lang ?? ''));
                $part = trim((string)($name->part ?? ''));
                $firstResult = $firstResult ?: $id ?: $part;
                if (!$langResult && $lang === 'fin') {
                    $langResult = $id ?: $part;
                }
            }
        }
        return $langResult ?: $firstResult;
    }

    /**
     *  Get description
     *
     * @return string
     */
    protected function getDescription(): string
    {
        if (!empty($this->doc->scopecontent)) {
            $desc = [];
            foreach ($this->doc->scopecontent as $el) {
                foreach ($el->p as $p) {
                    $desc[] = str_replace(
                        ["\r\n", "\n\r", "\r", "\n"],
                        '   /   ',
                        trim(html_entity_decode((string)$p))
                    );
                }
            }
            if (!empty($desc)) {
                return implode('   /   ', $desc);
            }
        }
        return '';
    }

    /**
     * Check whether the given node is a time interval element
     * and should not be included when collecting name elements.
     *
     * @param \SimpleXMLElement $node Node
     *
     * @return bool
     */
    protected function isTimeIntervalNode(\SimpleXMLElement $node): bool
    {
        return mb_strtolower((string)$node->attributes()->localtype, 'UTF-8')
            === self::RELATOR_TIME_INTERVAL;
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
        $matchAttributes = function (\SimpleXMLElement $check) use (&$ids, &$fileIds): void {
            if ($check && $attrs = $check->attributes()) {
                if ($identifier = trim((string)$attrs->identifier)) {
                    $ids[] = $identifier;
                }
                if ($linktitle = trim((string)$attrs->linktitle)) {
                    $fileIds[] = $linktitle;
                }
            }
        };

        foreach ($this->doc->did->daoset ?? [] as $set) {
            $matchAttributes($set);
            foreach ($set->dao as $dao) {
                $matchAttributes($dao);
            }
        }
        foreach ($this->doc->did->dao ?? [] as $dao) {
            $matchAttributes($dao);
        }
        $fileIds = [...$ids, ...$fileIds];
        return $this->resultCache[$cacheKey] = compact('ids', 'fileIds');
    }
}
