<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\DataGoKr\DataGoKr;
use App\Services\Registries\Sources\Mfds\MfdsMapper;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are synthetic (no service key was available to record a live response): the
 * field names follow the official Swagger spec of the data.go.kr API; value formats (dates,
 * status and phase vocabulary, items nesting) are unverified.
 */
final class MfdsMapperTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/registries/mfds/'.$name);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $file, int $index): array
    {
        $item = DataGoKr::parse(self::fixture($file), 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY')['items'][$index];

        return ['item' => $item, 'url' => 'https://apis.data.go.kr/1471000/ClncExamPlanDtlService03/getClncExamPlanDtlInq03?type=json&clnc_test_sn='.$item['CLNC_TEST_SN']];
    }

    private function map(array $payload): MappedStudy
    {
        return (new MfdsMapper)->map($payload);
    }

    public function test_an_approved_plan_is_mapped(): void
    {
        $study = $this->map(self::payload('synthetic_page1.json', 0));
        $a = $study->attributes;

        $this->assertSame('mfds', $study->source);
        $this->assertSame('202400123', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertStringStartsWith('A Multicenter, Randomized, Double-blind, Phase 3 Study', $a['brief_title']);
        $this->assertStringStartsWith('진행성 비소세포폐암 환자를 대상으로', $a['official_title']);
        $this->assertSame('RECRUITING', $a['overall_status']);
        $this->assertSame('PHASE3', $a['phase']);
        $this->assertSame('INTERVENTIONAL', $a['study_type']);
        $this->assertSame('2024-03-01', $a['start_date']);
        $this->assertSame('2027-12-31', $a['completion_date'], 'Without an actual completion date, the last observation end.');
        $this->assertSame('2024-01-15', $a['study_first_post_date']);
        $this->assertSame('2024-06-10', $a['last_update_post_date']);
        $this->assertSame(120, $a['enrollment_count']);
        $this->assertSame('ESTIMATED', $a['enrollment_type']);
        $this->assertNull($a['sponsor_name']);
        $this->assertNull($a['eligibility_criteria']);
        $this->assertStringContainsString('Randomization: 무작위배정', (string) $a['detailed_description']);
        $this->assertStringContainsString('Original developer country: 미국', (string) $a['detailed_description']);
        $this->assertFalse($a['has_results']);

        $this->assertSame([['condition' => '진행성 비소세포폐암'], ['condition' => '기관지 및 폐의 악성 신생물']], $study->relations['conditions']);
        $this->assertSame([['keyword' => '신생물']], $study->relations['keywords']);
        $this->assertSame([
            ['type' => 'DRUG', 'name' => 'ABC-101', 'description' => 'Ingredient: abcitinib; Dosage form: 정제; Administration: 1일 1회 경구 투여; Duration: 질병 진행 시까지'],
            ['type' => 'DRUG', 'name' => 'Placebo', 'description' => null],
        ], $study->relations['interventions']);
        $this->assertSame([['name' => 'Example Pharma Inc.', 'class' => null]], $study->relations['collaborators']);
        $this->assertSame(['서울대학교병원', '삼성서울병원', '세브란스병원'], array_column($study->relations['locations'], 'facility'));
        $this->assertSame(['Korea, Republic of'], array_values(array_unique(array_column($study->relations['locations'], 'country'))));
        $this->assertSame(['PRIMARY', 'SECONDARY'], array_column($study->relations['outcomes'], 'type'));
        $this->assertSame('무진행생존기간(PFS)', $study->relations['outcomes'][0]['measure']);
        $this->assertSame([], $study->relations['secondaryIds'], 'MFDS publishes no other registry identifiers.');

        $this->assertSame('RECRUITING', $study->facts?->overallStatus);
        $this->assertSame('json', $study->raw?->format);
        $this->assertStringContainsString('"CLNC_TEST_SN":"202400123"', (string) $study->raw?->content);
        $this->assertStringEndsWith('clnc_test_sn=202400123', (string) $study->raw?->url);
    }

    public function test_missing_optional_fields_become_null(): void
    {
        $study = $this->map(self::payload('synthetic_page1.json', 1));
        $a = $study->attributes;

        $this->assertSame('202300456', $study->sourceId);
        $this->assertSame($a['brief_title'], $a['official_title'], 'Without an English title, the Korean one is used.');
        $this->assertSame('TERMINATED', $a['overall_status']);
        $this->assertSame('PHASE1', $a['phase']);
        $this->assertSame('2023-10-15', $a['completion_date'], 'The actual completion date wins.');
        $this->assertSame('2023-05-02', $a['last_update_post_date'], 'Without a change approval, the approval date.');
        $this->assertNull($a['enrollment_count'], 'Two numbers in the text: no honest single count.');
        $this->assertNull($a['enrollment_type']);
        $this->assertNull($a['brief_summary']);
        $this->assertNull($a['detailed_description']);
        $this->assertSame([], $study->relations['collaborators']);
        $this->assertSame([], $study->relations['outcomes']);
        $this->assertSame([], $study->relations['keywords']);
        $this->assertSame([
            ['type' => 'DRUG', 'name' => 'XYZ-7', 'description' => null],
            ['type' => 'DRUG', 'name' => '위약대조약', 'description' => 'Comparator'],
        ], $study->relations['interventions']);
        $this->assertCount(1, $study->relations['locations']);
        $this->assertNull($study->relations['locations'][0]['facility']);
        $this->assertSame('Korea, Republic of', $study->relations['locations'][0]['country']);
    }

    public function test_a_single_item_object_is_read_as_a_list(): void
    {
        $study = $this->map(self::payload('synthetic_page2.json', 0));

        $this->assertSame('202200789', $study->sourceId);
        $this->assertNull($study->attributes['overall_status'], '진행중 does not say whether the trial recruits.');
        $this->assertSame('PHASE2, PHASE3', $study->attributes['phase']);
        $this->assertSame(300, $study->attributes['enrollment_count']);
    }

    public function test_status_and_phase_vocabularies(): void
    {
        $this->assertSame('NOT_YET_RECRUITING', MfdsMapper::status('모집 전')?->value);
        $this->assertSame('ACTIVE_NOT_RECRUITING', MfdsMapper::status('모집완료')?->value);
        $this->assertSame('COMPLETED', MfdsMapper::status('시험 완료')?->value);
        $this->assertSame('SUSPENDED', MfdsMapper::status('중지')?->value);
        $this->assertSame('WITHDRAWN', MfdsMapper::status('취소')?->value);
        $this->assertNull(MfdsMapper::status('승인'));

        $this->assertSame('PHASE1, PHASE2', MfdsMapper::phase('제1/2상'));
        $this->assertSame('PHASE2', MfdsMapper::phase('2b상'));
        $this->assertSame('EARLY_PHASE1', MfdsMapper::phase('0상'));
        $this->assertSame('PHASE4', MfdsMapper::phase('임상 4상'));
        $this->assertNull(MfdsMapper::phase('기타'));
        $this->assertNull(MfdsMapper::phase('생물학적동등성시험'));
    }

    public function test_a_plan_without_serial_number_is_rejected(): void
    {
        $payload = self::payload('synthetic_page1.json', 0);
        unset($payload['item']['CLNC_TEST_SN']);

        $this->assertNull((new MfdsMapper)->payloadId($payload));
        $this->expectException(InvalidStudyPayload::class);
        $this->map($payload);
    }

    public function test_a_plan_without_title_is_rejected(): void
    {
        $payload = self::payload('synthetic_page1.json', 0);
        $payload['item']['CLNC_TEST_TITLE'] = '';
        $payload['item']['CLNC_TEST_ENG_TITLE'] = null;

        $this->expectException(InvalidStudyPayload::class);
        $this->map($payload);
    }

    public function test_no_data_is_an_empty_result(): void
    {
        $result = DataGoKr::parse(self::fixture('synthetic_nodata.json'), 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY');

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['totalCount']);
    }

    public function test_a_quota_error_sent_with_http_200_fails_clearly(): void
    {
        try {
            DataGoKr::parse(self::fixture('synthetic_quota_exceeded.xml'), 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY');
            $this->fail('A quota error must not be read as data.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('quota for MFDS exceeded', $exception->getMessage());
            $this->assertStringContainsString('code 22', $exception->getMessage());
        }
    }

    public function test_a_refused_key_names_the_environment_variable(): void
    {
        try {
            DataGoKr::parse(self::fixture('synthetic_key_not_registered.json'), 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY');
            $this->fail('A key error must not be read as data.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('REGISTRY_MFDS_SERVICE_KEY', $exception->getMessage());
            $this->assertStringContainsString('SERVICE_KEY_IS_NOT_REGISTERED_ERROR', $exception->getMessage());
        }
    }

    public function test_a_body_that_is_not_a_result_fails(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        DataGoKr::parse('{"data": []}', 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY');
    }

    public function test_an_html_body_fails(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        DataGoKr::parse('<html><body>Maintenance</body></html>', 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY');
    }

    public function test_a_missing_key_is_refused_and_an_encoded_key_is_decoded(): void
    {
        $this->assertSame('ab+c/d==', DataGoKr::serviceKey('ab%2Bc%2Fd%3D%3D', 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY'));

        try {
            DataGoKr::serviceKey('  ', 'MFDS', 'REGISTRY_MFDS_SERVICE_KEY');
            $this->fail('An empty key must be refused.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('set REGISTRY_MFDS_SERVICE_KEY', $exception->getMessage());
        }
    }
}
