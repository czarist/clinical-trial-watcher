<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\EdaEgypt\EdaEgyptClient;
use App\Services\Registries\Sources\EdaEgypt\EdaEgyptMapper;
use PHPUnit\Framework\TestCase;

/**
 * The text fixture is the output of `pdftotext -layout -enc UTF-8` (poppler 25.12) for pages
 * 1-22 and 84-86 of the EDA registry edition of 07/07/2026.
 */
final class EdaEgyptMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/eda_egypt/';

    private const string PDF_URL = 'https://www.edaegypt.gov.eg/media/1gwbk0sz/ct-protocols-registry-july-2026.pdf';

    /**
     * @return array{edition: string|null, rows: list<array<string, string|null>>}
     */
    private static function parsed(): array
    {
        return EdaEgyptClient::parseRegistry((string) file_get_contents(self::FIXTURES.'registry-2026-07-07.txt'));
    }

    /**
     * @return array<string, MappedStudy> keyed by source id
     */
    private static function studies(): array
    {
        $parsed = self::parsed();
        $studies = [];

        foreach ($parsed['rows'] as $row) {
            $study = (new EdaEgyptMapper)->map([...$row, 'edition' => $parsed['edition'], 'url' => self::PDF_URL]);
            $studies[$study->sourceId] = $study;
        }

        return $studies;
    }

    public function test_the_newest_edition_is_found_on_the_landing_page(): void
    {
        $url = EdaEgyptClient::latestPdfUrl((string) file_get_contents(self::FIXTURES.'landing.html'), EdaEgyptClient::DEFAULT_LANDING_URL);

        $this->assertSame('https://www.edaegypt.gov.eg/media/gmlkwm0q/ct-protocols-registry-oct-final-14-10-2026.pdf', $url);
    }

    public function test_a_landing_page_without_editions_gives_no_url(): void
    {
        $this->assertNull(EdaEgyptClient::latestPdfUrl('<html><body><div class="news-details-description"><p>Soon</p></div></body></html>', EdaEgyptClient::DEFAULT_LANDING_URL));
    }

    public function test_rows_spanning_pages_are_joined_and_page_furniture_is_dropped(): void
    {
        $parsed = self::parsed();

        $this->assertSame('07/07/2026', $parsed['edition']);
        $this->assertCount(26, $parsed['rows']);
        $this->assertSame(['1.', '2.', '3.'], array_column(array_slice($parsed['rows'], 0, 3), 'sn'));
        $this->assertSame('89.', $parsed['rows'][25]['sn']);

        $first = $parsed['rows'][0];
        $this->assertStringEndsWith('who failed prior biologic treatment', (string) $first['title']);
        $this->assertStringContainsString('5-Internal Medicine Department, Faculty of Medicine, Zagazig University/NA', (string) $first['sites']);

        foreach ($parsed['rows'] as $row) {
            $this->assertStringNotContainsString('QF:BioInn', implode(' ', $row));
            $this->assertStringNotContainsString('Pharmaceutical Orange', implode(' ', $row));
        }
    }

    public function test_text_without_the_table_header_is_refused(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        EdaEgyptClient::parseRegistry("Registry updated 07/07/2026\nNothing to see here\n");
    }

    public function test_a_completed_study_is_mapped(): void
    {
        $study = self::studies()['M15-991'];

        $this->assertSame('eda_egypt', $study->source);
        $this->assertNull($study->nctId);
        $this->assertSame('M15-991', $study->attributes['org_study_id']);
        $this->assertStringStartsWith('A multi-center, randomized, double- blind', $study->attributes['brief_title']);
        $this->assertSame('COMPLETED', $study->attributes['overall_status']);
        $this->assertSame('2021-11-03', $study->attributes['completion_date']);
        $this->assertSame('2026-07-07', $study->attributes['status_verified_date']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('Abbvie', $study->attributes['sponsor_name']);

        $this->assertSame([['condition' => 'moderately to severely active Crohn’s disease who failed prior biologic treatment']], $study->relations['conditions']);
        $this->assertSame([['type' => 'BIOLOGICAL', 'name' => 'Risankizumab', 'description' => null]], $study->relations['interventions']);
        $this->assertCount(5, $study->relations['locations']);
        $this->assertSame('CRC, Faculty of Medicine, Alexandria University', $study->relations['locations'][0]['facility']);
        $this->assertSame('Egypt', $study->relations['locations'][0]['country']);
        $this->assertSame([], $study->relations['secondaryIds']);

        $this->assertSame('COMPLETED', $study->facts?->overallStatus);
        $this->assertSame('pdf', $study->raw?->format);
        $this->assertSame(self::PDF_URL, $study->raw->url);
        $this->assertStringContainsString('study_code: M15-991', $study->raw->content);
    }

    public function test_statuses_map_from_the_last_event(): void
    {
        $studies = self::studies();

        $this->assertSame('ACTIVE_NOT_RECRUITING', $studies['M16-000']->attributes['overall_status']);
        $this->assertSame('WITHDRAWN', $studies['STAND']->attributes['overall_status']);
        $this->assertSame('TERMINATED', $studies['CAIN457A2310']->attributes['overall_status']);
        $this->assertSame('RECRUITING', $studies['FUZIONCNTO1959CRD']->attributes['overall_status']);
        // Refusal and approval by EDA have no recruitment status.
        $this->assertNull($studies['Recbio']->attributes['overall_status']);
        $this->assertNull($studies['NW-Roselle-572023']->attributes['overall_status']);
    }

    public function test_wrapped_study_codes_keep_a_stable_id_and_combined_phases_are_split(): void
    {
        $studies = self::studies();

        $this->assertArrayHasKey('D910DC00001(Emerald-2)', $studies);
        $this->assertSame('D910DC0 0001 (Emerald- 2)', $studies['D910DC00001(Emerald-2)']->attributes['org_study_id']);
        $this->assertSame('PHASE2, PHASE3', $studies['Recbio']->attributes['phase']);
        $this->assertSame([['name' => 'IQVIA', 'class' => null]], $studies['D910DC00001(Emerald-2)']->relations['collaborators']);
    }

    public function test_herbal_products_and_missing_fields(): void
    {
        $study = self::studies()['NW-Roselle-572023'];

        $this->assertSame('OTHER', $study->relations['interventions'][0]['type']);
        $this->assertNull($study->attributes['completion_date']);

        $sparse = (new EdaEgyptMapper)->map(['study_code' => 'X-1', 'title' => 'A study']);
        $this->assertSame('X-1', $sparse->sourceId);
        $this->assertNull($sparse->attributes['overall_status']);
        $this->assertNull($sparse->attributes['phase']);
        $this->assertNull($sparse->attributes['sponsor_name']);
        $this->assertNull($sparse->attributes['status_verified_date']);
        $this->assertSame([], $sparse->relations['locations']);
        $this->assertSame([], $sparse->relations['interventions']);
        $this->assertNull($sparse->raw?->url);
    }

    public function test_every_record_has_the_same_attribute_keys(): void
    {
        $keys = array_map(static fn (MappedStudy $study): array => array_keys($study->attributes), array_values(self::studies()));

        $this->assertCount(1, array_unique(array_map('serialize', $keys)));
    }

    public function test_a_row_without_study_code_is_rejected(): void
    {
        $this->assertNull((new EdaEgyptMapper)->payloadId(['study_code' => '  ']));
        $this->expectException(InvalidStudyPayload::class);
        (new EdaEgyptMapper)->map(['study_code' => null, 'title' => 'Orphan row']);
    }
}
