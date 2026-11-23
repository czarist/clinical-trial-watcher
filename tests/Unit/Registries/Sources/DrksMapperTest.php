<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Sources\Drks\DrksClient;
use App\Services\Registries\Sources\Drks\DrksMapper;
use App\Services\Registries\Sources\Drks\DrksSource;
use App\Services\Registries\Sources\Drks\JsonArrayStream;
use PHPUnit\Framework\TestCase;

final class DrksMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/drks/';

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function records(): array
    {
        $records = json_decode((string) file_get_contents(self::FIXTURES.'records.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column($records, null, 'drksId');
    }

    public function test_an_interventional_record_is_mapped(): void
    {
        $study = (new DrksMapper)->map(self::records()['DRKS00021160']);

        $this->assertSame(DrksSource::KEY, $study->source);
        $this->assertSame('DRKS00021160', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertStringStartsWith('Randomized multi-centre open-label', (string) $study->attributes['brief_title']);
        $this->assertSame('Randomet2017', $study->attributes['acronym']);
        $this->assertSame('RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('2022-03-04', $study->attributes['start_date']);
        $this->assertSame('2030-10-01', $study->attributes['completion_date']);
        $this->assertSame('2020-05-26', $study->attributes['study_first_post_date']);
        $this->assertSame('2026-11-20', $study->attributes['last_update_post_date']);
        $this->assertSame(406, $study->attributes['enrollment_count']);
        $this->assertSame('ESTIMATED', $study->attributes['enrollment_type']);
        $this->assertSame('3 Months', $study->attributes['min_age']);
        $this->assertSame('18 Years', $study->attributes['max_age']);
        $this->assertSame('ALL', $study->attributes['gender']);
        $this->assertSame('GPOH gGmbH', $study->attributes['sponsor_name']);
        $this->assertSame('YES', $study->attributes['ipd_sharing']);
        $this->assertStringContainsString('Inclusion criteria:', (string) $study->attributes['eligibility_criteria']);

        // EU CT and EudraCT numbers link the record to the same study in other registries.
        $this->assertSame(
            [['2023-508926-91-00', 'EUCT'], ['2018-000533-13', 'EudraCT']],
            array_map(fn (array $row): array => [$row['secondary_id'], $row['type']], $study->relations['secondaryIds']),
        );

        $countries = array_column($study->relations['locations'], 'country');
        $this->assertContains('Germany', $countries);
        $this->assertContains('Austria', $countries);
        $this->assertContains(['facility' => 'Evangelisches Klinikum Bethel', 'city' => 'Bielefeld'], array_map(
            fn (array $row): array => ['facility' => $row['facility'], 'city' => $row['city']],
            $study->relations['locations'],
        ));
        $this->assertNotEmpty($study->relations['conditions']);
        $this->assertNotEmpty($study->relations['armGroups']);
        $this->assertSame('Arm 1', $study->relations['armGroups'][0]['label']);
        $this->assertCount(count($study->relations['armGroups']), $study->relations['interventions']);
        $this->assertSame('PRIMARY', $study->relations['outcomes'][0]['type']);
        $this->assertSame('RECRUITING', $study->facts?->overallStatus);
    }

    public function test_an_observational_record_cites_its_nct_number_and_keeps_no_personal_data(): void
    {
        $record = self::records()['DRKS00034741'];
        $study = (new DrksMapper)->map($record);

        $this->assertSame('OBSERVATIONAL', $study->attributes['study_type']);
        $this->assertNull($study->attributes['phase']);
        $this->assertSame('PICS-DACH Steering Committee', $study->attributes['sponsor_name']);
        $this->assertSame([['secondary_id' => 'NCT07290712', 'type' => 'ClinicalTrials.gov', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['interventions']);
        $this->assertSame('Group 1', $study->relations['armGroups'][0]['label'] ?? null);

        $this->assertSame('json', $study->raw?->format);
        $this->assertSame('https://drks.de/search/en/trial/DRKS00034741', $study->raw->url);
        $raw = json_decode($study->raw->content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('DRKS00034741', $raw['drksId']);
        $this->assertArrayNotHasKey('lastName', $raw['trialContacts'][0]['contact']);
        $this->assertArrayNotHasKey('email', $raw['trialContacts'][0]['contact']);
        $this->assertArrayHasKey('affiliation', $raw['trialContacts'][0]['contact']);
        $this->assertStringNotContainsString('Redacted', $study->raw->content);
    }

    public function test_a_completed_record_maps_its_status(): void
    {
        $study = (new DrksMapper)->map(self::records()['DRKS00041448']);

        $this->assertSame('COMPLETED', $study->attributes['overall_status']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([['facility' => 'Klinik und Poliklinik für Allgemein-, Viszeral-, Thorax- und Transplantationschirurgie', 'city' => 'Köln', 'country' => 'Germany']], array_map(
            fn (array $row): array => ['facility' => $row['facility'], 'city' => $row['city'], 'country' => $row['country']],
            $study->relations['locations'],
        ));
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $study = (new DrksMapper)->map(self::records()['DRKS00040000']);

        $this->assertSame('Nur ein deutscher Titel', $study->attributes['brief_title']);
        foreach (['overall_status', 'phase', 'study_type', 'start_date', 'completion_date', 'sponsor_name', 'enrollment_count', 'min_age', 'eligibility_criteria', 'ipd_sharing'] as $column) {
            $this->assertNull($study->attributes[$column], $column);
        }
        $this->assertFalse($study->attributes['has_results']);
        $this->assertSame('2025-06-02', $study->attributes['last_update_post_date']);
        foreach ($study->relations as $name => $rows) {
            $this->assertSame([], $rows, $name);
        }
    }

    public function test_every_status_has_an_honest_equivalent(): void
    {
        $mapper = new DrksMapper;
        $record = self::records()['DRKS00040000'];
        $expected = [
            'PENDING' => 'NOT_YET_RECRUITING',
            'INVITE_ONLY' => 'ENROLLING_BY_INVITATION',
            'SUSPENDED' => 'SUSPENDED',
            'DISCONTINIUED' => 'TERMINATED',
            'WITHDRAWN' => 'WITHDRAWN',
            'COMPLETE_FOLLOW_UP_CONTINUING' => 'ACTIVE_NOT_RECRUITING',
            'SOMETHING_NEW' => null,
        ];

        foreach ($expected as $status => $mapped) {
            $record['recruitment'] = ['status' => $status];
            $this->assertSame($mapped, $mapper->map($record)->attributes['overall_status'], $status);
        }
    }

    public function test_a_record_without_a_valid_id_is_rejected(): void
    {
        $mapper = new DrksMapper;
        $this->assertNull($mapper->payloadId(['drksId' => 'NCT01234567']));

        $this->expectException(InvalidStudyPayload::class);
        $mapper->map(['drksId' => null, 'trialDescriptions' => []]);
    }

    public function test_only_records_registered_or_updated_from_2025_are_open_licensed(): void
    {
        $records = self::records();

        $this->assertTrue(DrksClient::isOpenLicensed($records['DRKS00021160'])); // registered 2020, updated 2026
        $this->assertTrue(DrksClient::isOpenLicensed(['registrationDrks' => '2025-01-01', 'lastUpdate' => null]));
        $this->assertFalse(DrksClient::isOpenLicensed($records['DRKS00012345'])); // registered 2019, updated 2024
        $this->assertFalse(DrksClient::isOpenLicensed(['registrationDrks' => null, 'lastUpdate' => 'soon']));
    }

    public function test_record_urls_are_built_only_for_drks_ids(): void
    {
        $source = (new \ReflectionClass(DrksSource::class))->newInstanceWithoutConstructor();

        $this->assertSame('https://drks.de/search/en/trial/DRKS00021160', $source->recordUrl('DRKS00021160'));
        $this->assertNull($source->recordUrl('DRKS0002116'));
        $this->assertNull($source->recordUrl('../DRKS00021160'));
    }

    public function test_the_json_array_is_streamed_and_resumed_from_an_offset(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'drks');
        file_put_contents($path, "\u{FEFF} [\n {\"a\": \"x}{\\\"]\", \"b\": [1, {\"c\": 2}]},\n{\"a\": \"é\"} ,{}\n]\n");
        $stream = new JsonArrayStream($path);

        $elements = iterator_to_array($stream->elements());
        $this->assertSame(['{"a": "x}{\"]", "b": [1, {"c": 2}]}', '{"a": "é"}', '{}'], array_values($elements));

        $offsets = array_keys($elements);
        $this->assertSame(['{"a": "é"}', '{}'], array_values(iterator_to_array($stream->elements($offsets[0]))));
        $this->assertSame([], iterator_to_array($stream->elements($offsets[2])));

        // A small chunk boundary inside strings and escapes changes nothing.
        file_put_contents($path, '['.str_repeat('{"t": "'.str_repeat('\\"ab', 30_000).'"},', 3).'{}]');
        $this->assertCount(4, iterator_to_array((new JsonArrayStream($path))->elements()));

        file_put_contents($path, '[]');
        $this->assertSame([], iterator_to_array($stream->elements()));
        unlink($path);
    }

    public function test_a_file_that_is_not_an_array_of_objects_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'drks');

        foreach (['{"error": "maintenance"}', '[1, 2]', '[{"a": 1}', '[{"a": 1},]', '<html></html>'] as $content) {
            file_put_contents($path, $content);

            try {
                iterator_to_array((new JsonArrayStream($path))->elements());
                $this->fail("Accepted {$content}");
            } catch (RegistryRequestFailed) {
                $this->addToAssertionCount(1);
            }
        }

        unlink($path);
    }
}
