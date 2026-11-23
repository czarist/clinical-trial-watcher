<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Fetching\FetchedDocument;
use App\Services\Registries\Sources\NciCts\NciCtsClient;
use App\Services\Registries\Sources\NciCts\NciCtsMapper;
use App\Services\Registries\Sources\NciCts\NciCtsSource;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are synthetic: they follow the shape of NCI's own mock trial documents, but no
 * live API response was available to compare with.
 */
final class NciCtsMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/nci_cts/';

    private static function document(string $fixture, ?string $contentType = 'application/json; charset=utf-8'): FetchedDocument
    {
        return new FetchedDocument('https://clinicaltrialsapi.cancer.gov/api/v2/trials', (string) file_get_contents(self::FIXTURES.$fixture), $contentType, CarbonImmutable::now());
    }

    /**
     * @return array<string, mixed>
     */
    private static function trial(string $nciId): array
    {
        foreach (['synthetic_trials_page1.json', 'synthetic_trials_page2.json'] as $page) {
            foreach (NciCtsClient::parseTrials(self::document($page))['trials'] as $trial) {
                if ($trial['nci_id'] === $nciId) {
                    return $trial;
                }
            }
        }

        self::fail("No fixture trial {$nciId}.");
    }

    public function test_a_full_trial_is_mapped(): void
    {
        $study = (new NciCtsMapper)->map(self::trial('NCI-2026-00123'));

        $this->assertSame(NciCtsSource::KEY, $study->source);
        $this->assertSame('NCI-2026-00123', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('Synthetic Drug A for the Treatment of Recurrent Synthetic Lymphoma', $study->attributes['brief_title']);
        $this->assertStringStartsWith('A Phase 1/2 Synthetic Trial', $study->attributes['official_title']);
        $this->assertSame('SYNTH-1', $study->attributes['acronym']);
        $this->assertSame('SYN-2026-01', $study->attributes['org_study_id']);
        $this->assertSame('RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('PHASE1, PHASE2', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2026-01-15', $study->attributes['start_date']);
        $this->assertNull($study->attributes['primary_completion_date']);
        $this->assertSame('2029-12-31', $study->attributes['completion_date']);
        $this->assertSame('2026-10-30', $study->attributes['status_verified_date']);
        $this->assertSame(60, $study->attributes['enrollment_count']);
        $this->assertSame('ESTIMATED', $study->attributes['enrollment_type']);
        $this->assertSame('Synthetic Cancer Center', $study->attributes['sponsor_name']);
        $this->assertSame('18 Years', $study->attributes['min_age']);
        $this->assertNull($study->attributes['max_age']);
        $this->assertSame('ALL', $study->attributes['gender']);
        $this->assertSame("Inclusion criteria:\nAge >= 18 years\nRecurrent synthetic lymphoma\n\nExclusion criteria:\nNo prior synthetic therapy", $study->attributes['eligibility_criteria']);
        $this->assertFalse($study->attributes['has_results']);

        // The NCT number links the record to its ClinicalTrials.gov registration; CTEP ids do not.
        $this->assertSame([['secondary_id' => 'NCT07012345', 'type' => 'NCT', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame([['name' => 'Synthetic Cooperative Group', 'class' => null]], $study->relations['collaborators']);
        $this->assertSame([['condition' => 'Recurrent Synthetic Lymphoma']], $study->relations['conditions']);
        $this->assertSame([['keyword' => 'synthetic'], ['keyword' => 'lymphoma']], $study->relations['keywords']);
        $this->assertSame([
            ['type' => 'DRUG', 'name' => 'Synthetic Drug A', 'description' => null],
            ['type' => 'OTHER', 'name' => 'Quality-of-Life Assessment', 'description' => null],
            ['type' => 'RADIATION', 'name' => 'Radiation Therapy', 'description' => null],
        ], $study->relations['interventions']);
        $this->assertSame(['Arm I (drug A)', 'Arm II (radiation therapy)'], array_column($study->relations['armGroups'], 'label'));
        $this->assertSame(['PRIMARY', 'SECONDARY', 'OTHER'], array_column($study->relations['outcomes'], 'type'));
        $this->assertSame('Up to 28 days', $study->relations['outcomes'][0]['time_frame']);

        $this->assertSame([
            'facility' => 'Synthetic Cancer Center', 'city' => 'Bethesda', 'state' => 'MD', 'country' => 'United States',
            'status' => 'RECRUITING', 'zip' => '20892', 'latitude' => 38.9847, 'longitude' => -77.0947,
        ], $study->relations['locations'][0]);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->relations['locations'][1]['status']);
        $this->assertNull($study->relations['locations'][1]['latitude']);

        $this->assertSame('RECRUITING', $study->facts?->overallStatus);
        $this->assertSame('json', $study->raw?->format);
        $this->assertSame('https://clinicaltrialsapi.cancer.gov/api/v2/trials/NCI-2026-00123', $study->raw?->url);

        foreach (['contact_name', 'contact_email', 'contact_phone', 'org_email', 'org_phone', 'principal_investigator', 'central_contact', 'Redacted'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $study->raw?->content);
        }
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $study = (new NciCtsMapper)->map(self::trial('NCI-2025-04567'));

        $this->assertSame('NCI-2025-04567', $study->sourceId);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('OBSERVATIONAL', $study->attributes['study_type']);
        $this->assertSame('NA', $study->attributes['phase']);
        $this->assertSame('0.5 Years', $study->attributes['min_age']);
        $this->assertSame('75 Years', $study->attributes['max_age']);
        $this->assertSame('FEMALE', $study->attributes['gender']);

        foreach (['official_title', 'acronym', 'org_study_id', 'start_date', 'completion_date', 'status_verified_date', 'brief_summary',
            'detailed_description', 'enrollment_count', 'enrollment_type', 'sponsor_name', 'eligibility_criteria', 'last_update_post_date'] as $column) {
            $this->assertNull($study->attributes[$column], $column);
        }

        foreach ($study->relations as $name => $rows) {
            $this->assertSame([], $rows, $name);
        }
    }

    public function test_registry_identifiers_among_other_ids_are_kept_and_other_values_dropped(): void
    {
        $study = (new NciCtsMapper)->map(self::trial('NCI-2018-09876'));

        $this->assertSame(['NCT03456789', 'ISRCTN12345678'], array_column($study->relations['secondaryIds'], 'secondary_id'));
        $this->assertSame(['NCT', 'ISRCTN'], array_column($study->relations['secondaryIds'], 'type'));
        $this->assertSame('COMPLETED', $study->attributes['overall_status']);
        $this->assertSame('PHASE2, PHASE3', $study->attributes['phase']);
        $this->assertSame('2018-03-01', $study->attributes['start_date']);
        $this->assertSame('2022-06-30', $study->attributes['primary_completion_date']);
        $this->assertNull($study->attributes['enrollment_count']);
        $this->assertSame('50 Years', $study->attributes['min_age']);
        $this->assertNull($study->attributes['max_age']);
        $this->assertTrue($study->attributes['has_results']);
    }

    public function test_a_record_without_a_valid_nci_id_is_rejected(): void
    {
        $mapper = new NciCtsMapper;
        $this->assertNull($mapper->payloadId(['nci_id' => 'NCT01234567']));

        $this->expectException(InvalidStudyPayload::class);
        $mapper->map(['brief_title' => 'No id']);
    }

    public function test_the_list_response_is_parsed(): void
    {
        $listing = NciCtsClient::parseTrials(self::document('synthetic_trials_page1.json'));
        $this->assertSame(3, $listing['total']);
        $this->assertSame(['NCI-2026-00123', 'NCI-2025-04567'], array_column($listing['trials'], 'nci_id'));

        $this->assertSame(['total' => 0, 'trials' => []], NciCtsClient::parseTrials(self::document('synthetic_trials_empty.json')));
        $this->assertSame(0, NciCtsClient::decodeToken(null));
        $this->assertSame(50, NciCtsClient::decodeToken('50'));
    }

    public function test_the_html_page_returned_without_a_key_is_not_parsed(): void
    {
        try {
            NciCtsClient::parseTrials(self::document('unauthenticated.html', 'text/html'));
            $this->fail('An HTML page must not be parsed as a trial list.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('REGISTRY_NCI_CTS_API_KEY', $exception->getMessage());
        }
    }

    public function test_a_changed_response_shape_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);

        NciCtsClient::parseTrials(new FetchedDocument('https://example.test', '{"trials":[]}', 'application/json', CarbonImmutable::now()));
    }

    public function test_record_urls_are_built_only_for_nci_ids(): void
    {
        $source = (new \ReflectionClass(NciCtsSource::class))->newInstanceWithoutConstructor();

        $this->assertSame('https://www.cancer.gov/research/participate/clinical-trials-search/v?id=NCI-2026-00123', $source->recordUrl('NCI-2026-00123'));
        $this->assertNull($source->recordUrl('NCT07012345'));
    }
}
