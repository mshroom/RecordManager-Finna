<?php

/**
 * Finna EAD3 Record Driver Test Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2020.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Finna\Record;

use RecordManager\Finna\Record\Ead3;

/**
 * Finna EAD3 Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead3Test extends \RecordManagerTest\Base\Record\RecordTestBase
{
    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa()
    {
        // 1985-02-02/1995-12-01
        $fields = $this->createRecord(Ead3::class, 'ahaa.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1985-02-02 TO 1995-12-01]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa2()
    {
        // 1985-02/1995-12
        $fields = $this->createRecord(Ead3::class, 'ahaa2.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1985-02-01 TO 1995-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa3()
    {
        // 1985-02/1995-11
        $fields = $this->createRecord(Ead3::class, 'ahaa3.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1985-02-01 TO 1995-11-30]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa4()
    {
        // 1985/1995
        $fields = $this->createRecord(Ead3::class, 'ahaa4.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1985-01-01 TO 1995-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa5()
    {
        // uuuu-uu-10/1995-05-uu
        $fields = $this->createRecord(Ead3::class, 'ahaa5.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[0000-01-10 TO 1995-05-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa6()
    {
        // unknown/open
        $fields = $this->createRecord(Ead3::class, 'ahaa6.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[0000-01-01 TO 9999-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa8()
    {
        // uuuu-12-uu/unknown
        $fields = $this->createRecord(Ead3::class, 'ahaa8.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[0000-12-01 TO 9999-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa9()
    {
        // 1900/1940-03-02
        $fields = $this->createRecord(Ead3::class, 'ahaa9.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1900-01-01 TO 1940-03-02]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa10()
    {
        // 195u/1960-01-01
        $fields = $this->createRecord(Ead3::class, 'ahaa10.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1950-01-01 TO 1960-01-01]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa11()
    {
        // uu5u-11-05/u960-01-01
        $fields = $this->createRecord(Ead3::class, 'ahaa11.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[0050-11-05 TO 9960-01-01]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa12()
    {
        // Prefer unitdate with label "Ajallinen kattavuus"
        $fields = $this->createRecord(Ead3::class, 'ahaa12.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1970-01-01 TO 1971-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa13()
    {
        // Discard unitdate with label "Ajallinen kattavuus" when starttime or
        // endtime is unknown
        $fields = $this->createRecord(Ead3::class, 'ahaa13.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1992-01-01 TO 1993-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Helper function for setting new values into XML tags.
     *
     * @param string $fixture  Fixture to modify
     * @param string $replace  What XML tag is to be changed
     * @param string $newValue New value for the replace XML element
     *
     * @return string
     */
    public function getFixtureWithNewValue(
        string $fixture,
        string $replace,
        string $newValue
    ): string {
        $oldTag = "/>(.*?)<\/$replace>/";
        $newTag = '>' . $newValue . "</$replace>";
        return preg_replace(
            $oldTag,
            $newTag,
            $fixture
        ) ?? '';
    }

    /**
     * Data provider for testTitleYearRange
     *
     * @return Array
     */
    public static function getTestTitleYearRange()
    {
        $ndash = html_entity_decode('&#x2013;', ENT_NOQUOTES, 'UTF-8');
        $mdash = html_entity_decode('&#x2014;', ENT_NOQUOTES, 'UTF-8');
        $ltr = "\u{200E}";

        $noYear = [
            'no year ndash' => [
                'title' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
                'expected' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
            ],
            'no year mdash' => [
                'title' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
                'expected' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
            ],
            'no year dash' => [
                'title' => 'Opintokirja. Helsingin yliopisto (1932 - 1935)',
                'expected' => 'Opintokirja. Helsingin yliopisto (1932 - 1935)',
            ],
            'no year with year' => [
                'title' => 'Opintokirja. Helsingin yliopisto 1932',
                'expected' => 'Opintokirja. Helsingin yliopisto 1932',
            ],
            'no year without year range' => [
                'title' => 'Opintokirja. Helsingin yliopisto',
                'expected' => 'Opintokirja. Helsingin yliopisto' .
                "{$ltr} (1932{$ndash}1935)",
            ],
        ];

        $never = [
            'never ndash' => [
                'title' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
                'expected' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
            ],
            'never without year range' => [
                'title' => 'Opintokirja. Helsingin yliopisto',
                'expected' => 'Opintokirja. Helsingin yliopisto',
            ],
        ];

        $noMatch = [
            'no match ndash' => [
                'title' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
                'expected' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
            ],
            'no match mdash' => [
                'title' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
                'expected' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
            ],
            'no match dash' => [
                'title' => 'Opintokirja. Helsingin yliopisto (1932 - 1935)',
                'expected' => 'Opintokirja. Helsingin yliopisto (1932 - 1935)',
            ],
            'no match dash without whitespaces' => [
                'title' => 'Opintokirja. 1932 Helsingin yliopisto',
                'expected' => 'Opintokirja. 1932 Helsingin yliopisto',
            ],
            'no match without year range' => [
                'title' => 'Opintokirja. Helsingin yliopisto',
                'expected' => 'Opintokirja. Helsingin yliopisto' .
                "{$ltr} (1932{$ndash}1935)",
            ],
        ];

        $noMatches = [
            'no matches ndash' => [
                'title' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
                'expected' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
            ],
            'no matches mdash' => [
                'title' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
                'expected' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
            ],
            'no matches dash' => [
                'title' => 'Opintokirja. Helsingin yliopisto (1932)',
                'expected'
                    => 'Opintokirja. Helsingin yliopisto (1932)' .
                    "{$ltr} (1932{$ndash}1935)",
            ],
            'no matches with years' => [
                'title' => 'Opintokirja. 1932 Helsingin yliopisto 1935',
                'expected' => 'Opintokirja. 1932 Helsingin yliopisto 1935',
            ],
            'no matches without years' => [
                'title' => 'Opintokirja. Helsingin yliopisto',
                'expected' => 'Opintokirja. Helsingin yliopisto' .
                "{$ltr} (1932{$ndash}1935)",
            ],
        ];

        $always = [
            'always ndash' => [
                'title' => "Opintokirja. Helsingin yliopisto (1932{$ndash}1935)",
                'expected' =>
                    'Opintokirja. Helsingin yliopisto '
                    . "(1932{$ndash}1935){$ltr} (1932{$ndash}1935)",
            ],
            'always mdash' => [
                'title' => "Opintokirja. Helsingin yliopisto 1932{$mdash}1935",
                'expected'
                    => 'Opintokirja. Helsingin yliopisto '
                    . "1932{$mdash}1935{$ltr} (1932{$ndash}1935)",
            ],
            'always dash' => [
                'title' => 'Opintokirja. Helsingin yliopisto (1932 - 1935)',
                'expected' =>
                    'Opintokirja. Helsingin yliopisto '
                    . "(1932 - 1935){$ltr} (1932{$ndash}1935)",
            ],
            'always title with a year' => [
                'title' => 'Opintokirja. 1932 Helsingin yliopisto',
                'expected'
                    => 'Opintokirja. 1932 Helsingin yliopisto' .
                    "{$ltr} (1932{$ndash}1935)",
            ],
            'always without year' => [
                'title' => 'Opintokirja. Helsingin yliopisto',
                'expected' => 'Opintokirja. Helsingin yliopisto' .
                "{$ltr} (1932{$ndash}1935)",
            ],
        ];

        return [
            [
                'driverParams' => [
                    'enrichTitleWithYearRange=no_year_exists',
                ],
                'tests' => $noYear,
            ],
            [
                'driverParams' => [
                    'enrichTitleWithYearRange=never',
                ],
                'tests' => $never,
            ],
            [
                'driverParams' => [
                    'enrichTitleWithYearRange=no_match_exists',
                ],
                'tests' => $noMatch,
            ],
            [

                'driverParams' => [
                    'enrichTitleWithYearRange=no_matches_exist',
                ],
                'tests' => $noMatches,
            ],
            [
                'driverParams' => [
                    'enrichTitleWithYearRange=always',
                ],
                'tests' => $always,
            ],
        ];
    }

    /**
     * Test AHAA EAD3 title year range handling
     *
     * @param array $driverParams Datasource settings
     * @param array $tests        Titles and results
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getTestTitleYearRange')]
    public function testTitleYearRange($driverParams, $tests)
    {
        $fixture = $this->getFixture('record/ahaa14.xml', 'Finna');
        foreach ($tests as $test) {
            $fixture = $this->getFixtureWithNewValue(
                $fixture,
                'unittitle',
                $test['title']
            );
            $record = $this->createRecordFromString(
                $fixture,
                Ead3::class,
                [
                    '__unit_test_no_source__' => [
                        'driverParams' => $driverParams,
                    ],
                ]
            )->toSolrArray();
            $this->assertEquals($test['expected'], $record['title']);
        }
    }

    /**
     * Test FSD EAD3 record handling
     *
     * @return void
     */
    public function testFsd()
    {
        // uu5u-11-05/u960-01-01
        $fields = $this->createRecord(Ead3::class, 'fsd.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[2014-02-17 TO 2014-03-14]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test YKSA EAD3 record handling
     *
     * @return void
     */
    public function testYksa()
    {
        // <unitdate>1918-1931</unitdate>
        // 1917-XX-XX
        // uuuu-uu-uu (not to be included)
        $fields = $this->createRecord(Ead3::class, 'yksa.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals(
            ['[1918-01-01 TO 1931-12-31]', '[1917-01-01 TO 1917-12-31]'],
            $fields['search_daterange_mv']
        );
        $this->assertEquals(
            ['Testiperhe', 'Tellervo Testihenkilö'],
            $fields['author']
        );
        $this->assertEquals(
            ['Teppo Testihenkilö'],
            $fields['author2']
        );
        $this->assertEquals(
            ['koirat', 'Tessu Testikoira'],
            $fields['topic']
        );
        $this->assertEquals(
            ['Kupittaa', 'Turku', 'Vaasa', 'Vaasan keskusta', 'Viipuri'],
            $fields['geographic']
        );
        $this->assertEquals(
            ['Kupittaa', 'Turku', 'Vaasa', 'Vaasan keskusta', 'Viipuri'],
            $fields['geographic_facet']
        );
        $this->assertEquals(
            ['POINT(21.616 63.093)'],
            $fields['location_geo']
        );
        $this->assertEquals(
            [
                'http://www.yso.fi/onto/yso/p94466',
                'http://localhost',
                'http://www.yso.fi/onto/yso/p94486',
            ],
            $fields['geographic_id_str_mv']
        );
    }

    /**
     * Test YKSA EAD3 record handling
     *
     * @return void
     */
    public function testYksa2()
    {
        // <unitdate>1931</unitdate>
        $fields = $this->createRecord(Ead3::class, 'yksa2.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertContains(
            '[1931-01-01 TO 1931-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test online and free online booleans
     *
     * @return void
     */
    public function testOnlineBooleans()
    {
        // online record with daoset
        $fields = $this->createRecord(Ead3::class, 'yksa2.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('1', $fields['online_boolean']);
        $this->assertEquals('1', $fields['free_online_boolean']);
        // online record without daoset
        $fields = $this->createRecord(Ead3::class, 'sks2.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('1', $fields['online_boolean']);
        $this->assertEquals('1', $fields['free_online_boolean']);
        // not online
        $fields = $this->createRecord(Ead3::class, 'ead3_online_boolean.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertNotContains('online_boolean', $fields);
        $this->assertNotContains('free_online_boolean', $fields);
    }

    /**
     * Data provider for testSKS
     *
     * @return array
     */
    public static function sksProvider(): array
    {
        return [
            'addIdToHierarchyTitle=true' => [
                'true',
                [
                    'no_lang' => ['1 1 Sundvall Gustaf Edvard S 1:a) 1'],
                    'fi' => ['1 1 Sundvall Gustaf Edvard S 1:a) 1'],
                    'sv' => ['1 1 Sundvall Gustaf Edvard S 1:a) 1 swe'],
                ],
            ],
            'addIdToHierarchyTitle=false' => [
                'false',
                null,
            ],
        ];
    }

    /**
     * Test SKS EAD3 record handling
     *
     * @param string $addIdToHierarchyTitle    Value for addIdToHierarchyTitle driver param
     * @param ?array $expectedTitleInHierarchy Expected title_in_hierarchy field contents
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sksProvider')]
    public function testSKS(string $addIdToHierarchyTitle, ?array $expectedTitleInHierarchy): void
    {
        $fields = $this->createRecord(
            Ead3::class,
            'sks.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        "addIdToHierarchyTitle=$addIdToHierarchyTitle",
                    ],
                ],
            ],
            'Finna',
            [],
            [
                'Metadata Language Code Mappings' => [
                    'fin' => 'fi',
                    'swe' => 'sv',
                    'en-gb' => 'en',
                    'eng' => 'en',
                    'sme' => 'se',
                ],
            ],
        )->toSolrArray();
        unset($fields['fullrecord']);
        $ltr = "\u{200E}";

        $expected = [
            'record_format' => 'ead3',
            'ctrlnum' => [],
            'allfields' => [
                'Yksityisaineisto',
                'Joku muu instituutio',
                'SKS:n arkisto, Hallituskatu 1, HKI',
                '242790397',
                'xx.xx.1881-xx.xx.1881',
                'Sundvall Gustaf Edvard S 1:a) 1',
                'Sundvall Gustaf Edvard S 1:a) 1 swe',
                '1',
                's/sundvall_gustaf_edvard/001/00001/00005',
                'SKS KRA S Sundvall Gustaf Edvard 1: a) 1',
                'KRA-K-103174062',
                'A1576669851fpriz',
                'Suomi',
                'Aineistotyyppi: Text 5 Styck',
                'Aineistotyyppi: Text 5 Pieces',
                'Aineistotyyppi: Teksti, järjestetty 5 Kappaletta',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
                'Sundvall, Gustaf Edvard',
                'Teksti',
                'Text',
                'Text',
                'Luvia',
                'Luvia',
                'Luvia',
                'folk tales',
                'kansansadut',
                'folksagor',
                'fairy tales',
                'sadut',
                'sagor',
                'folklore collectors',
                'perinteenkerääjät',
                'traditionsinsamlare',
                'Sundvall, Gustaf Edvard',
                '1881-12-31 - 1881-12-31',
                'Sundwall, Gustaf Edvard',
                '1881-12-31 - 1881-12-31',
                'Sundvall, Gustaf Edvard',
                '1881-12-31 - 1881-12-31',
                'Sundwall, Gustaf Edvard',
                '1881-12-31 - 1881-12-31',
                'Sundvall, Gustaf Edvard',
                'unknown - open',
                'Sundwall, Gustaf Edvard',
                'unknown - open',
                'Ingman, Anders Wilhelm',
                '1881-12-31 - 1881-12-31',
                'Ingman, A.W.',
                '1881-12-31 - 1881-12-31',
                'Sundvall, Gustaf Edvard',
                'unknown - open',
                'Sundwall, Gustaf Edvard',
                'unknown - open',
                'Tietosisältö',
                'G. E. Sundvallin tallentama murresatu Luvialta.',
                'Tietopalvelun tarjoamispaikka',
                'SKS:n arkisto, Hallituskatu 1, HKI',
                'Tekninen tyyppi',
                'Digitaalinen',
                'Alkuperäisyys',
                'Kopio',
                'Digitaalisen aineiston tiedostomuoto',
                'TIFF - Tagged Image File Format',
                'Gustaf Edvard Sundvallin kokoelma',
                '238612737',
            ],
            'description' => 'G. E. Sundvallin tallentama murresatu Luvialta.',
            'author' => [
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
            ],
            'author2' => [
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
            ],
            'author_sort' => 'Sundvall, Gustaf Edvard',
            'author_corporate' => [],
            'geographic_facet' => [
                'Luvia',
                'Luvia',
                'Luvia',
            ],
            'geographic' => [
                'Luvia',
                'Luvia',
                'Luvia',
            ],
            'location_geo' => [],
            'topic_facet' => [
                'folk tales',
                'kansansadut',
                'folksagor',
                'fairy tales',
                'sadut',
                'sagor',
                'folklore collectors',
                'perinteenkerääjät',
                'traditionsinsamlare',
            ],
            'topic' => [
                'folk tales',
                'kansansadut',
                'folksagor',
                'fairy tales',
                'sadut',
                'sagor',
                'folklore collectors',
                'perinteenkerääjät',
                'traditionsinsamlare',
            ],
            'format' => 'Teksti',
            'institution' => '102268433',
            'series' => 'Tekstit/Gustaf Edvard Sundvallin kokoelma',
            'title_sub' => '1',
            'title_short' => 'Sundvall Gustaf Edvard S 1:a) 1' . $ltr . ' (1881)',
            'title' => '1 Sundvall Gustaf Edvard S 1:a) 1' . $ltr . ' (1881)',
            'title_en_txt' => '',
            'title_fi_txt' => '1 Sundvall Gustaf Edvard S 1:a) 1' . $ltr . ' (1881)',
            'title_se_txt' => '',
            'title_sv_txt' => '1 Sundvall Gustaf Edvard S 1:a) 1 swe' . $ltr . ' (1881)',
            'title_sort' => '1 sundvall gustaf edvard s 1 a 1' . $ltr . ' (1881)',
            'title_full' => '1 Sundvall Gustaf Edvard S 1:a) 1' . $ltr . ' (1881)',
            'title_alt' => [
                '1 Sundvall Gustaf Edvard S 1:a) 1' . $ltr . ' (1881)',
                '1 Sundvall Gustaf Edvard S 1:a) 1 swe' . $ltr . ' (1881)',
            ],
            'language' => [
                'fin',
            ],
            'physical' => [],
            'thumbnail' => '',
            'hierarchytype' => 'Default',
            'hierarchy_top_id' => '237990354',
            'hierarchy_top_title' => 'Gustaf Edvard Sundvallin kokoelma',
            'hierarchy_sequence' => '0000003',
            'hierarchy_parent_id' => '237990354_238008149',
            'hierarchy_parent_title' => 'Tekstit/Gustaf Edvard Sundvallin kokoelma',
            'unit_daterange' => '[1881-01-01 TO 1881-12-31]',
            'search_daterange_mv' => [
                '[1881-01-01 TO 1881-12-31]',
            ],
            'era_facet' => '1881',
            'main_date_str' => '1881',
            'main_date' => '1881-01-01T00:00:00Z',
            'hierarchy_sequence_str' => '0000003',
            'source_str_mv' => '102268433',
            'datasource_str_mv' => '__unit_test_no_source__',
            'online_boolean' => '1',
            'online_str_mv' => '102268433',
            'free_online_boolean' => '1',
            'free_online_str_mv' => '102268433',
            'identifier' => '238612737',
            'material' => [
                'Aineistotyyppi: Text 5 Styck',
                'Aineistotyyppi: Text 5 Pieces',
                'Aineistotyyppi: Teksti, järjestetty 5 Kappaletta',
            ],
            'usage_rights_str_mv' => [
                'restricted',
            ],
            'usage_rights_ext_str_mv' => [
                'restricted',
            ],
            'author_variant' => [
                'Sundwall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Ingman, A.W.',
                'Sundwall, Gustaf Edvard',
            ],
            'author_facet' => [
                'Sundvall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
            ],
            'author2_id_str_mv' => [
                'EAC_228204328',
                'EAC_228598319',
            ],
            'author2_id_role_str_mv' => [
                'EAC_228204328###Luovuttaja',
                'EAC_228204328###Luovuttaja',
                'EAC_228204328###Kirjoittaja',
                'EAC_228204328###Kirjoittaja',
                'EAC_228204328###Kerääjä',
                'EAC_228204328###Kerääjä',
                'EAC_228598319###Luovuttaja',
                'EAC_228598319###Luovuttaja',
                'EAC_228204328###Kokoelmanmuodostaja',
                'EAC_228204328###Kokoelmanmuodostaja',
                'EAC_228204328###Luovuttaja',
                'EAC_228204328###Kirjoittaja',
                'EAC_228204328###Kerääjä',
                'EAC_228598319###Luovuttaja',
                'EAC_228204328###Kokoelmanmuodostaja',
            ],
            'format_ext_str_mv' => 'Teksti',
            'topic_id_str_mv' => [
                'http://www.yso.fi/onto/koko/p9995',
                'http://www.yso.fi/onto/koko/p16542',
                'http://www.yso.fi/onto/koko/p74073',
            ],
            'geographic_id_str_mv' => [
                'http://www.yso.fi/onto/yso/p105341',
                'http://www.yso.fi/onto/yso/p105341',
                'http://www.yso.fi/onto/yso/p105341',
            ],
            'media_type_str_mv' => [
                'image/tiff',
            ],
            'identifier_txtP_mv' => [
                '242790411',
                '242790416',
                '242790421',
                '242790426',
                '242790431',
            ],
            'file_identifier_str_mv' => [
                '242790411',
                '242790416',
                '242790421',
                '242790426',
                '242790431',
                'Tiedosto 1',
                'Tiedosto 2',
                'Tiedosto 3',
                'Tiedosto 4',
                'Tiedosto 5',
                'Very good filename',
            ],
            'isbn' => [],
            'issn' => [],
            'publishDate' => [],
            'publishDateRange' => [],
            'publishDateSort' => '',
        ];
        if (null !== $expectedTitleInHierarchy) {
            $expected['title_in_hierarchy'] = $expectedTitleInHierarchy['no_lang'];
            $expected['title_in_hierarchy_fi_str_mv'] = $expectedTitleInHierarchy['fi'];
            $expected['title_in_hierarchy_sv_str_mv'] = $expectedTitleInHierarchy['sv'];
        }

        $this->assertEquals(
            $expected,
            $fields
        );
    }

    /**
     * Test date range parsing
     *
     * @return void
     */
    public function testParseDateRange()
    {
        $record = $this->createRecord(Ead3::class, 'yksa.xml', [], 'Finna');
        $reflection = new \ReflectionObject($record);
        $parseDateRange = $reflection->getMethod('parseDateRange');
        $parseDateRange->setAccessible(true);

        $this->assertEquals(
            [
                'date' => ['2021-01-01T00:00:00Z', '2021-12-31T23:59:59Z'],
                'startDateUnknown' => false,
                'endDateUnknown' => false,
            ],
            $parseDateRange->invokeArgs($record, ['2021/2021'])
        );
        $this->assertEquals(
            [
                'date' => ['2022-01-01T00:00:00Z', '2022-12-31T23:59:59Z'],
                'startDateUnknown' => false,
                'endDateUnknown' => false,
            ],
            $parseDateRange->invokeArgs($record, ['2022/2021'])
        );
        $this->assertEquals(
            null,
            $parseDateRange->invokeArgs($record, ['11999/2021'])
        );
        $this->assertEquals(
            null,
            $parseDateRange->invokeArgs($record, ['2010, 2020, 2021'])
        );
    }

    /**
     * Test authors
     *
     * @return void
     */
    public function testAuthors()
    {
        $fields = $this->createRecord(Ead3::class, 'ead3_authors.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals(
            ['Kimmo Kissakuvaaja', 'Kirsi Kissakirjailija', 'Kasper Kissankasvattaja', 'Arkistonmuodostaja Henkilö'],
            $fields['author']
        );
        $this->assertEquals(
            ['Kimi Kissakuvaaja'],
            $fields['author_variant']
        );
        $this->assertEquals(
            ['Kissat turvaan ry', 'Kissanmuona Oy', 'Arkistonmuodostaja Organisaatio'],
            $fields['author_corporate']
        );
        $this->assertEquals(
            ['Lasse Luovuttaja', 'Kerttu Kerääjä'],
            $fields['author2']
        );
        $this->assertEquals(
            [
                'Kimmo Kissakuvaaja', 'Kirsi Kissakirjailija', 'Kasper Kissankasvattaja', 'Arkistonmuodostaja Henkilö',
                'Lasse Luovuttaja', 'Kerttu Kerääjä', 'Kissat turvaan ry', 'Kissanmuona Oy',
                'Arkistonmuodostaja Organisaatio',
            ],
            $fields['author_facet']
        );
        $this->assertEquals(
            ['EAC_004', 'EAC_001', 'EAC_002', 'EAC_003', 'EAC_008', 'EAC_005', 'EAC_006'],
            $fields['author2_id_str_mv']
        );
        $this->assertEquals(
            [
                'EAC_001###Valokuvaaja', 'EAC_001###Valokuvaaja', 'EAC_002###Kirjoittaja',
                'EAC_004###Kissojensuojeluyhdistys', 'EAC_006###luovuttaja',
            ],
            $fields['author2_id_role_str_mv']
        );
        //Check that agents as subjects are indexed as subjects rather than authors
        $this->assertEquals(
            ['kissat', 'Kisu Misu', 'Pekka Töpöhäntä'],
            $fields['topic']
        );
        $this->assertEquals(
            ['EAC_007', 'EAC_009'],
            $fields['topic_id_str_mv']
        );
    }

    /**
     * Test format
     *
     * @return void
     */
    public function testFormat()
    {
        // file-level record with AI08 genreform
        $fields = $this->createRecord(Ead3::class, 'sks.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('Teksti', $fields['format']);
        // file-level record with AI08 and AI57 genreforms
        $fields = $this->createRecord(Ead3::class, 'sks2.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('Teksti/Kirje', $fields['format']);
        // file-level record with genreform without encodinganalog
        $fields = $this->createRecord(Ead3::class, 'sks5.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('Kirje', $fields['format']);
        // fonds-level record with AI08 genreform
        $fields = $this->createRecord(Ead3::class, 'sks3.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('fonds/Teksti', $fields['format']);
        // collection-level record with genreform without encodinganalog
        $fields = $this->createRecord(Ead3::class, 'sks4.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('collection/Valokuva', $fields['format']);
    }

    /**
     * Test institution
     *
     * @return void
     */
    public function testInstitution()
    {
        // AI46 institution code
        $fields = $this->createRecord(Ead3::class, 'sks.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('102268433', $fields['institution']);
        // other institution code
        $fields = $this->createRecord(Ead3::class, 'sks2.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('12345', $fields['institution']);
        // institution name with lang attribute
        $fields = $this->createRecord(Ead3::class, 'sks3.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('Instituutio suomeksi', $fields['institution']);
        $fields = $this->createRecord(Ead3::class, 'sks4.xml', [], 'Finna')
            ->toSolrArray();
        $this->assertEquals('Instituutio suomeksi', $fields['institution']);
    }
}
