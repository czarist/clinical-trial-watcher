<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\Cris\CrisClient;
use App\Services\Registries\Sources\Cris\CrisMapper;
use App\Services\Registries\Sources\DataGoKr\DataGoKr;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are synthetic (no service key was available to record a live response): field
 * names and sample values follow the data.go.kr documentation of dataset 3033869; the
 * envelope nesting, repeated fields and the status vocabulary beyond "Recruiting" are unverified.
 */
final class CrisMapperTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/registries/cris/'.$name);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $file): array
    {
        return [
            'records' => DataGoKr::parse(self::fixture($file), 'CRIS', 'REGISTRY_CRIS_SERVICE_KEY')['items'],
            'url' => 'https://apis.data.go.kr/1352159/crisinfodataview/detail?resultType=JSON&crisNumber=KCT0002243',
        ];
    }

    private function map(array $payload): MappedStudy
    {
        return (new CrisMapper)->map($payload);
    }

    public function test_a_detailed_record_is_mapped(): void
    {
        $study = $this->map(self::payload('synthetic_detail_KCT0002243.json'));
        $a = $study->attributes;

        $this->assertSame('cris', $study->source);
        $this->assertSame('KCT0002243', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('The efficacy of PlasmaBlade in cardiac device generator replacement', $a['brief_title']);
        $this->assertStringStartsWith('Comparison of efficacy of PlasmaBlade', $a['official_title']);
        $this->assertSame('RECRUITING', $a['overall_status']);
        $this->assertSame('INTERVENTIONAL', $a['study_type']);
        $this->assertSame('NA', $a['phase']);
        $this->assertSame('2016-06-16', $a['start_date']);
        $this->assertSame('2019-06-01', $a['completion_date']);
        $this->assertSame('2017-02-23', $a['study_first_post_date']);
        $this->assertSame('2026-11-20', $a['last_update_post_date']);
        $this->assertSame(200, $a['enrollment_count']);
        $this->assertSame('ESTIMATED', $a['enrollment_type']);
        $this->assertSame('Asan Medical Center', $a['sponsor_name']);
        $this->assertSame('ALL', $a['gender']);
        $this->assertSame('19 Years', $a['min_age']);
        $this->assertNull($a['max_age']);
        $this->assertNull($a['eligibility_criteria']);
        $this->assertStringStartsWith('The most common technique', (string) $a['brief_summary']);
        $this->assertStringContainsString('Allocation: RCT', (string) $a['detailed_description']);

        $this->assertSame([['condition' => 'Diseases of the circulatory system']], $study->relations['conditions']);
        $this->assertSame(['PlasmaBlade group', 'Electrocautery group'], array_column($study->relations['armGroups'], 'label'));
        $this->assertSame('DEVICE', $study->relations['interventions'][0]['type']);
        $this->assertStringStartsWith('Patients needing generator replacement', (string) $study->relations['interventions'][0]['description']);
        $this->assertSame([['name' => 'Medtronic Korea', 'class' => null]], $study->relations['collaborators']);
        $this->assertSame(['Asan Medical Center'], array_column($study->relations['locations'], 'facility'));
        $this->assertSame('Korea, Republic of', $study->relations['locations'][0]['country']);
        $this->assertSame([[
            'type' => 'PRIMARY',
            'measure' => 'Device related complications',
            'description' => 'Device related complications',
            'time_frame' => 'Before discharge and 1 year after the procedure',
        ]], $study->relations['outcomes']);
        $this->assertSame([['secondary_id' => 'NCT03012345', 'type' => null, 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);

        $this->assertSame('json', $study->raw?->format);
        $raw = (string) $study->raw?->content;
        $this->assertStringContainsString('"trial_id":"KCT0002243"', $raw);
        $this->assertStringNotContainsString('telephone', $raw, 'Personal data is dropped from the raw document.');
        $this->assertStringNotContainsString('scientific_name_en', $raw);
        $this->assertStringNotContainsString('public_name_kr', $raw);
        $this->assertStringContainsString('crisNumber=KCT0002243', (string) $study->raw?->url);
    }

    public function test_rows_of_one_trial_are_merged_and_missing_fields_become_null(): void
    {
        $study = $this->map(self::payload('synthetic_detail_KCT0009876.json'));
        $a = $study->attributes;

        $this->assertSame('KCT0009876', $study->sourceId);
        $this->assertSame('만성 요통 환자의 운동 프로그램 관찰연구', $a['brief_title'], 'Korean when no English is given.');
        $this->assertSame('ACTIVE_NOT_RECRUITING', $a['overall_status']);
        $this->assertSame('OBSERVATIONAL', $a['study_type']);
        $this->assertSame('FEMALE', $a['gender']);
        $this->assertSame('40 Years', $a['min_age']);
        $this->assertSame('70 Years', $a['max_age']);
        $this->assertNull($a['start_date']);
        $this->assertNull($a['completion_date']);
        $this->assertNull($a['enrollment_count']);
        $this->assertNull($a['enrollment_type']);
        $this->assertNull($a['sponsor_name']);
        $this->assertNull($a['brief_summary']);
        $this->assertStringContainsString('Number of groups: 1', (string) $a['detailed_description']);
        $this->assertSame(['경희대학교병원', '강남세브란스병원'], array_column($study->relations['locations'], 'facility'));
        $this->assertSame([], $study->relations['interventions']);
        $this->assertSame([], $study->relations['outcomes']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['conditions']);
    }

    public function test_a_list_item_alone_is_mapped(): void
    {
        $item = DataGoKr::parse(self::fixture('synthetic_list_page1.json'), 'CRIS', 'REGISTRY_CRIS_SERVICE_KEY')['items'][0];
        $study = $this->map(['records' => [$item], 'url' => null]);

        $this->assertSame('KCT0002243', $study->sourceId);
        $this->assertStringStartsWith('Comparison of efficacy', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('NA', $study->attributes['phase']);
        $this->assertSame([['type' => 'PRIMARY', 'measure' => '시술후부터 퇴원 전까지 기기 관련 합병증', 'description' => '시술후부터 퇴원 전까지 기기 관련 합병증', 'time_frame' => null]], $study->relations['outcomes']);
    }

    public function test_a_nested_envelope_with_a_single_item_is_read(): void
    {
        $list = DataGoKr::parse(self::fixture('synthetic_list_page2.json'), 'CRIS', 'REGISTRY_CRIS_SERVICE_KEY');

        $this->assertSame(3, $list['totalCount']);
        $this->assertCount(1, $list['items']);
        $this->assertSame('KCT0005555', $list['items'][0]['trial_id']);
    }

    public function test_status_phase_and_identifier_vocabularies(): void
    {
        $this->assertSame('NOT_YET_RECRUITING', CrisMapper::status('Not yet recruiting')?->value);
        $this->assertSame('ACTIVE_NOT_RECRUITING', CrisMapper::status('Active, not recruiting')?->value);
        $this->assertSame('COMPLETED', CrisMapper::status('연구 종료')?->value);
        $this->assertSame('TERMINATED', CrisMapper::status('Stopped early')?->value);
        $this->assertSame('WITHDRAWN', CrisMapper::status('Withdrawn')?->value);
        $this->assertNull(CrisMapper::status('Unknown status'));

        $this->assertSame('PHASE1, PHASE2', CrisMapper::phase('Phase 1/Phase 2'));
        $this->assertSame('PHASE3', CrisMapper::phase('Phase III'));
        $this->assertSame('EARLY_PHASE1', CrisMapper::phase('Phase 0'));
        $this->assertSame('PHASE2', CrisMapper::phase('2상'));
        $this->assertNull(CrisMapper::phase('Bioequivalence'));

        $this->assertSame('KCT0002243', CrisMapper::normalizeId(' kct 0002243 '));
        $this->assertNull(CrisMapper::normalizeId('KCT02243'));
        $this->assertSame(['6 Months', '18 Years'], CrisMapper::ageRange('6Month~18Year'));
    }

    public function test_items_are_compared_with_the_updated_since_day(): void
    {
        $this->assertTrue(CrisClient::changedSince(['date_updated' => '2026-11-20'], '2026-11-20'));
        $this->assertFalse(CrisClient::changedSince(['date_updated' => '2026-11-19'], '2026-11-20'));
        $this->assertTrue(CrisClient::changedSince(['date_updated' => '', 'date_registration' => '2026-11-21'], '2026-11-20'));
        $this->assertTrue(CrisClient::changedSince([], '2026-11-20'), 'Without a date the detail is read.');
    }

    public function test_a_record_without_kct_number_is_rejected(): void
    {
        $payload = self::payload('synthetic_detail_KCT0002243.json');
        $payload['records'][0]['trial_id'] = 'CRIS-1';

        $this->assertNull((new CrisMapper)->payloadId($payload));
        $this->expectException(InvalidStudyPayload::class);
        $this->map($payload);
    }

    public function test_a_record_without_title_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        $this->map(['records' => [['trial_id' => 'KCT0001111']]]);
    }
}
