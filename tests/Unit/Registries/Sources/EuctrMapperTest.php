<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Sources\Euctr\EuctrClient;
use App\Services\Registries\Sources\Euctr\EuctrMapper;
use App\Services\Registries\Sources\Euctr\EuctrSource;
use PHPUnit\Framework\TestCase;

final class EuctrMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/euctr/';

    private const string URL = 'https://www.clinicaltrialsregister.eu/ctr-search/rest/download/full?query=&page=1&mode=current_page';

    /**
     * Payloads as the client builds them from a download.
     *
     * @return list<array<string, mixed>>
     */
    private function payloads(string $file): array
    {
        $payloads = [];

        foreach (EuctrClient::splitTrials((string) file_get_contents(self::FIXTURES.$file)) as $eudract => $blocks) {
            $payloads[] = ['eudract' => $eudract, 'blocks' => $blocks, 'url' => self::URL];
        }

        return $payloads;
    }

    public function test_the_text_layout_is_parsed_into_fields(): void
    {
        $blocks = EuctrClient::splitTrials((string) file_get_contents(self::FIXTURES.'download_full_2015-002555-10.txt'));
        $fields = EuctrMapper::fields($blocks['2015-002555-10'][0]);
        $byKey = [];

        foreach ($fields as [$key, $value]) {
            $byKey[$key] ??= $value;
        }

        $this->assertSame('2015-002555-10', $byKey['EudraCT Number']);
        $this->assertSame('Completed', $byKey['Trial Status']);
        $this->assertSame('United Kingdom', $byKey['B.1.3.4 Country']);
        // A value with no text on its own line continues on the next one.
        $this->assertSame('Oral use', $byKey['D.3.7 Routes of administration for this IMP']);
        // Multi-line values keep their lines.
        $this->assertStringContainsString("\n• Male or female", $byKey['E.3 Principal inclusion criteria']);
        // Headings are not fields.
        $this->assertArrayNotHasKey('B.5.3 Address', $byKey);
    }

    public function test_an_eea_trial_is_mapped(): void
    {
        $study = (new EuctrMapper)->map($this->payloads('download_full_2015-002555-10.txt')[0]);

        $this->assertSame(EuctrSource::KEY, $study->source);
        $this->assertSame('2015-002555-10', $study->sourceId);
        $this->assertSame('A trial to see whether the drug OC459 reduces the asthma symptoms induced by infection with the common cold', $study->attributes['brief_title']);
        $this->assertSame('Effect of the CRTH2 antagonist OC459 on the response to rhinovirus challenge in asthma', $study->attributes['official_title']);
        $this->assertSame('ORCA2015', $study->attributes['org_study_id']);
        $this->assertSame('COMPLETED', $study->attributes['overall_status']);
        $this->assertSame('PHASE2', $study->attributes['phase']);
        $this->assertSame('2015-09-29', $study->attributes['study_first_post_date']);
        $this->assertSame('2018-01-18', $study->attributes['completion_date']);
        $this->assertNull($study->attributes['start_date']);
        $this->assertSame(44, $study->attributes['enrollment_count']);
        $this->assertSame('Imperial College', $study->attributes['sponsor_name']);
        $this->assertSame('OTHER', $study->attributes['sponsor_class']);
        $this->assertSame('ALL', $study->attributes['gender']);
        $this->assertStringStartsWith("Inclusion criteria:\n• Age 18-55 years", $study->attributes['eligibility_criteria']);

        // The NCT number links the record to ClinicalTrials.gov; the sponsor code is not an identifier.
        $this->assertSame([['secondary_id' => 'NCT02660489', 'type' => 'NCT', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame([['condition' => 'Asthma']], $study->relations['conditions']);
        $this->assertContains(['keyword' => 'Allergic asthma'], $study->relations['keywords']);
        $this->assertContains(['keyword' => 'Diseases [C] - Respiratory Tract Diseases [C08]'], $study->relations['keywords']);
        $this->assertSame([['type' => 'DRUG', 'name' => 'OC459', 'description' => 'INN: OC000459']], $study->relations['interventions']);
        $this->assertSame(['Medical Research Council', 'Atopix Therapeutics Limited'], array_column($study->relations['collaborators'], 'name'));
        $this->assertSame([['facility' => null, 'city' => null, 'state' => null, 'country' => 'United Kingdom', 'status' => 'Completed', 'zip' => null, 'latitude' => null, 'longitude' => null]], $study->relations['locations']);
        $this->assertSame(['PRIMARY', 'SECONDARY'], array_column($study->relations['outcomes'], 'type'));
        $this->assertSame('This will be assessed on day 14 post-rhinovirus inoculation.', $study->relations['outcomes'][0]['time_frame']);
    }

    public function test_the_protocols_of_each_member_state_are_merged_into_one_record(): void
    {
        $payloads = $this->payloads('download_full_two_states.txt');

        $this->assertCount(1, $payloads);
        $this->assertCount(2, $payloads[0]['blocks']);

        $study = (new EuctrMapper)->map($payloads[0]);

        $this->assertSame(['United Kingdom', 'Germany'], array_column($study->relations['locations'], 'country'));
        $this->assertSame(['Completed', 'Prematurely Ended'], array_column($study->relations['locations'], 'status'));
        // Completed in one state and ended prematurely in another: completed.
        $this->assertSame('COMPLETED', $study->attributes['overall_status']);
        // The earliest date the trial was entered in EudraCT.
        $this->assertSame('2015-09-29', $study->attributes['study_first_post_date']);
    }

    public function test_a_state_still_ongoing_leaves_the_status_unknown(): void
    {
        $payload = $this->payloads('download_full_two_states.txt')[0];
        $payload['blocks'][1] = str_replace('Trial Status: Prematurely Ended', 'Trial Status: Ongoing', $payload['blocks'][1]);

        $this->assertNull((new EuctrMapper)->map($payload)->attributes['overall_status']);
    }

    public function test_a_third_country_trial_maps_missing_fields_to_null(): void
    {
        $study = (new EuctrMapper)->map($this->payloads('download_full_2019-003781-41.txt')[0]);

        $this->assertSame('2019-003781-41', $study->sourceId);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame(4, $study->attributes['enrollment_count']);
        $this->assertSame('2026-11-02', $study->attributes['study_first_post_date']);
        $this->assertNull($study->attributes['completion_date']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['locations']);
        // The trade name is preferred; the INN is kept in the description.
        $this->assertSame('Luxturna', $study->relations['interventions'][0]['name']);
        $this->assertSame('INN: Voretigene neparvovec', $study->relations['interventions'][0]['description']);
    }

    public function test_the_contact_point_is_never_mapped_nor_kept_in_the_raw_text(): void
    {
        $payload = $this->payloads('download_full_2015-002555-10.txt')[0];
        $payload['blocks'][0] = str_replace('B.5.6 E-mail: Redacted', 'B.5.6 E-mail: jane.doe@example.org', $payload['blocks'][0]);
        $study = (new EuctrMapper)->map($payload);

        $this->assertNotNull($study->raw);
        $this->assertSame('txt', $study->raw->format);
        $this->assertSame(self::URL, $study->raw->url);
        $this->assertStringContainsString('A.2 EudraCT number: 2015-002555-10', $study->raw->content);
        $this->assertStringNotContainsString('jane.doe@example.org', $study->raw->content);
        $this->assertStringNotContainsString('B.5.2 Functional name of contact point', $study->raw->content);
        $this->assertStringNotContainsString('jane.doe@example.org', json_encode([$study->attributes, $study->relations], JSON_THROW_ON_ERROR));
    }

    public function test_a_payload_without_a_valid_eudract_number_is_rejected(): void
    {
        $this->assertNull((new EuctrMapper)->payloadId(['eudract' => '2023-505807-21-00']));
        $this->expectException(InvalidStudyPayload::class);

        (new EuctrMapper)->map(['eudract' => 'ORCA2015', 'blocks' => []]);
    }

    public function test_downloads_are_validated(): void
    {
        $this->assertSame([], EuctrClient::splitTrials((string) file_get_contents(self::FIXTURES.'download_full_empty.txt')));

        $this->expectException(RegistryRequestFailed::class);
        EuctrClient::splitTrials("<!DOCTYPE html>\n<html><body>Service unavailable</body></html>");
    }

    public function test_page_tokens_and_record_urls(): void
    {
        $this->assertSame([2, '2015-002555-10'], EuctrClient::decodeToken(EuctrClient::encodeToken(2, '2015-002555-10')));

        $source = (new \ReflectionClass(EuctrSource::class))->newInstanceWithoutConstructor();
        $this->assertSame('https://www.clinicaltrialsregister.eu/ctr-search/search?query=2015-002555-10', $source->recordUrl('2015-002555-10'));
        $this->assertNull($source->recordUrl('2015-002555-10-GB'));
    }
}
