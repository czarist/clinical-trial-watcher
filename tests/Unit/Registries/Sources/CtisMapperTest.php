<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Sources\Ctis\CtisClient;
use App\Services\Registries\Sources\Ctis\CtisMapper;
use App\Services\Registries\Sources\Ctis\CtisSource;
use PHPUnit\Framework\TestCase;

final class CtisMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/ctis/';

    /**
     * A payload as the client builds it: the retrieve document with its search row.
     *
     * @return array<string, mixed>
     */
    private function payload(string $ctNumber): array
    {
        $trial = json_decode((string) file_get_contents(self::FIXTURES."retrieve_{$ctNumber}.json"), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];

        foreach (['search_page1.json', 'search_page2.json'] as $page) {
            $rows = [...$rows, ...CtisClient::parseSearch((string) file_get_contents(self::FIXTURES.$page))['rows']];
        }

        foreach ($rows as $row) {
            if ($row['ctNumber'] === $ctNumber) {
                $trial['searchResult'] = $row;
            }
        }

        return $trial;
    }

    public function test_a_multi_country_transitioned_trial_is_mapped(): void
    {
        $study = (new CtisMapper)->map($this->payload('2023-505807-21-00'));

        $this->assertSame(CtisSource::KEY, $study->source);
        $this->assertSame('2023-505807-21-00', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertStringStartsWith('A Study to Evaluate Efficacy and Safety of Mosunetuzumab', $study->attributes['brief_title']);
        $this->assertStringStartsWith('Phase III Randomized', $study->attributes['official_title']);
        $this->assertSame('GO42909', $study->attributes['acronym']);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2021-11-16', $study->attributes['start_date']);
        $this->assertSame('2029-12-31', $study->attributes['completion_date']);
        $this->assertSame('2024-11-07', $study->attributes['study_first_post_date']);
        $this->assertSame('2026-09-01', $study->attributes['last_update_post_date']);
        $this->assertSame('INDUSTRY', $study->attributes['sponsor_class']);
        $this->assertSame('ALL', $study->attributes['gender']);
        $this->assertGreaterThan(324, $study->attributes['enrollment_count']);
        $this->assertSame('ESTIMATED', $study->attributes['enrollment_type']);
        $this->assertStringStartsWith("Inclusion criteria:\nHistologically documented", $study->attributes['eligibility_criteria']);
        $this->assertStringContainsString("\n\nExclusion criteria:\n", $study->attributes['eligibility_criteria']);
        $this->assertFalse($study->attributes['has_results']);

        // The EudraCT number of the transitioned trial links it to its EU CTR record.
        $this->assertSame([['secondary_id' => '2020-005239-53', 'type' => 'EudraCT', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame([['condition' => 'Relapsed/refractory follicular lymphoma']], $study->relations['conditions']);
        $this->assertContains(['keyword' => 'Diseases [C] - Neoplasms [C04]'], $study->relations['keywords']);
        $this->assertContains(['keyword' => 'Follicular lymphoma'], $study->relations['keywords']);
        $names = array_column($study->relations['interventions'], 'name');
        $this->assertSame('RO 703-0816/F02-03', $names[0]);
        $this->assertContains('lenalidomide', $names);
        $this->assertContains('rituximab', $names);
        $this->assertSame('DRUG', $study->relations['interventions'][0]['type']);
        $this->assertNotEmpty($study->relations['outcomes']);
        $this->assertSame('PRIMARY', $study->relations['outcomes'][0]['type']);
        $this->assertContains('SECONDARY', array_column($study->relations['outcomes'], 'type'));

        $location = $study->relations['locations'][0];
        $this->assertSame('Spain', $location['country']);
        $this->assertSame('Murcia', $location['city']);
        $this->assertSame('Authorised', $location['status']);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->facts?->overallStatus);
    }

    public function test_a_new_trial_without_products_or_end_points_maps_missing_fields_to_null(): void
    {
        $payload = $this->payload('2026-527959-25-00');
        unset($payload['searchResult']);
        $study = (new CtisMapper)->map($payload);

        $this->assertSame('NOT_YET_RECRUITING', $study->attributes['overall_status']);
        // Without the search label, the phase comes from the trial category code (2 = Phase I bioequivalence).
        $this->assertSame('PHASE1', $study->attributes['phase']);
        $this->assertSame('2026-12-02', $study->attributes['start_date']);
        $this->assertSame('2027-02-19', $study->attributes['completion_date']);
        $this->assertSame('2026-11-22', $study->attributes['last_update_post_date']);
        $this->assertSame(26, $study->attributes['enrollment_count']);
        $this->assertNull($study->attributes['official_title']);
        $this->assertNull($study->attributes['brief_summary']);
        $this->assertNull($study->attributes['eligibility_criteria']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['interventions']);
        $this->assertSame([], $study->relations['outcomes']);
        // "No medical condition." of a healthy-volunteer trial is not a condition.
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame('Blueclinical Investigacao E Desenvolvimento Em Saude Lda.', $study->relations['locations'][0]['facility']);
    }

    public function test_the_phase_label_of_the_search_result_is_preferred(): void
    {
        $study = (new CtisMapper)->map($this->payload('2025-520887-17-00'));

        $this->assertSame('PHASE4', $study->attributes['phase']);
        $this->assertSame('2019-004038-41', $study->relations['secondaryIds'][0]['secondary_id']);
        // N/A placeholders are not product names.
        $this->assertNotContains('N/A', array_column($study->relations['interventions'], 'name'));
    }

    public function test_unknown_status_codes_and_the_bare_authorised_label_map_to_null(): void
    {
        $payload = $this->payload('2026-527959-25-00');
        $payload['ctPublicStatusCode'] = 11;
        $payload['searchResult']['ctStatus'] = 11;

        $this->assertNull((new CtisMapper)->map($payload)->attributes['overall_status']);
    }

    public function test_personal_data_is_never_mapped_nor_kept_in_the_raw_record(): void
    {
        $payload = $this->payload('2026-527959-25-00');
        $payload['authorizedApplication']['authorizedPartsII'][0]['trialSites'][0]['personInfo'] = [
            'firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@example.org', 'telephone' => '+3511234',
        ];
        $study = (new CtisMapper)->map($payload);

        $this->assertNotNull($study->raw);
        $this->assertSame('json', $study->raw->format);
        $this->assertSame('https://euclinicaltrials.eu/ctis-public-api/retrieve/2026-527959-25-00', $study->raw->url);
        $this->assertStringContainsString('"ctNumber":"2026-527959-25-00"', $study->raw->content);

        foreach (['Jane', 'Doe', 'jane@example.org', 'personInfo', 'publicContacts', 'functionalEmailAddress', '"email"', '"phone"'] as $personal) {
            $this->assertStringNotContainsString($personal, $study->raw->content);
            $this->assertStringNotContainsString($personal, json_encode([$study->attributes, $study->relations], JSON_THROW_ON_ERROR));
        }
    }

    public function test_a_payload_without_a_valid_eu_ct_number_is_rejected(): void
    {
        $this->assertNull((new CtisMapper)->payloadId(['ctNumber' => '2019-004038-41']));
        $this->expectException(InvalidStudyPayload::class);

        (new CtisMapper)->map(['ctNumber' => 'NCT01234567']);
    }

    public function test_search_responses_are_validated(): void
    {
        $listing = CtisClient::parseSearch((string) file_get_contents(self::FIXTURES.'search_page1.json'));
        $this->assertCount(2, $listing['rows']);
        $this->assertTrue($listing['nextPage']);
        $this->assertSame(['rows' => [], 'nextPage' => false], CtisClient::parseSearch((string) file_get_contents(self::FIXTURES.'search_empty.json')));

        $this->expectException(RegistryRequestFailed::class);
        CtisClient::parseSearch('{"results":[]}');
    }

    public function test_page_tokens_round_trip(): void
    {
        $this->assertSame([3, 20], CtisClient::decodeToken(CtisClient::encodeToken(3, 20)));
        $this->assertSame('2026-11-21', CtisClient::searchDate('21/11/2026')?->toDateString());
        $this->assertNull(CtisClient::searchDate('2026-11-21'));
        $this->assertSame('{"pagination":{"page":2,"size":5},"sort":{"property":"lastUpdated","direction":"DESC"},"searchCriteria":{}}', json_encode(CtisClient::searchBody(2, 5)));
    }

    public function test_record_urls_are_built_only_for_eu_ct_numbers(): void
    {
        $source = (new \ReflectionClass(CtisSource::class))->newInstanceWithoutConstructor();

        $this->assertSame('https://euclinicaltrials.eu/search-for-clinical-trials/?lang=en&EUCT=2026-527959-25-00', $source->recordUrl('2026-527959-25-00'));
        $this->assertNull($source->recordUrl('2019-004038-41'));
    }
}
