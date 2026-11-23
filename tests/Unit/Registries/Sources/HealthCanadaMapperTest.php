<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\HealthCanada\HealthCanadaClient;
use App\Services\Registries\Sources\HealthCanada\HealthCanadaMapper;
use PHPUnit\Framework\TestCase;

final class HealthCanadaMapperTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/registries/health_canada/'.$name);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(int $index): array
    {
        $protocol = HealthCanadaClient::parseProtocols(self::fixture('protocol_list.json'))[$index];
        $drugs = HealthCanadaClient::parseDrugProducts(self::fixture('drugproduct_list.json'));

        return [
            'protocol' => $protocol,
            'drugProducts' => $drugs[$protocol['protocol_id']] ?? [],
            'url' => "https://health-products.canada.ca/api/clinical-trial/protocol/?id={$protocol['protocol_id']}&lang=en&type=json",
        ];
    }

    private function map(array $payload): MappedStudy
    {
        return (new HealthCanadaMapper)->map($payload);
    }

    public function test_a_protocol_with_its_drug_product_is_mapped(): void
    {
        $study = $this->map(self::payload(0));
        $a = $study->attributes;

        $this->assertSame('health_canada', $study->source);
        $this->assertSame('29420', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('AM-101 IN THE POST-ACUTE TREATMENT OF PERIPHERAL TINNITUS 1 (AMPACT1) - AN OPEN-LABEL EXTENSION TO THE TACTT2 STUDY', $a['brief_title']);
        $this->assertSame($a['brief_title'], $a['official_title']);
        $this->assertSame('AM-101-CL-12-03', $a['org_study_id']);
        $this->assertSame('COMPLETED', $a['overall_status'], 'CLOSED');
        $this->assertSame('2014-03-11', $a['start_date']);
        $this->assertSame('2016-11-29', $a['completion_date']);
        $this->assertSame('INTERVENTIONAL', $a['study_type']);
        $this->assertNull($a['phase']);
        $this->assertSame('AURIS MEDICAL INC', $a['sponsor_name']);
        $this->assertSame('Study population: ADULT MALE, ADULT FEMALE', $a['eligibility_criteria']);

        $this->assertSame([['condition' => 'TINNITUS']], $study->relations['conditions']);
        $this->assertSame([['type' => null, 'name' => 'ESKETAMINE (AM-101)', 'description' => null]], $study->relations['interventions']);
        $this->assertSame(['Canada'], array_column($study->relations['locations'], 'country'));
        // Only Health Canada's own control number: the database cites no other registry.
        $this->assertSame([['secondary_id' => '171757', 'type' => 'Health Canada submission number', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['collaborators']);

        $this->assertSame('COMPLETED', $study->facts?->overallStatus);
        $this->assertSame('json', $study->raw?->format);
        $this->assertSame('https://health-products.canada.ca/api/clinical-trial/protocol/?id=29420&lang=en&type=json', $study->raw?->url);
        $raw = json_decode((string) $study->raw?->content, true);
        $this->assertSame(29420, $raw['protocol']['protocol_id']);
        $this->assertSame('ESKETAMINE (AM-101)', $raw['drugProducts'][0]['brand_name']);
    }

    public function test_missing_optional_fields_become_null_or_empty(): void
    {
        $study = $this->map(self::payload(2));
        $a = $study->attributes;

        $this->assertSame(array_keys($this->map(self::payload(0))->attributes), array_keys($a));
        $this->assertSame('29510', $study->sourceId);
        $this->assertNull($a['overall_status'], 'ONGOING does not say whether the trial recruits.');
        $this->assertNull($a['completion_date']);
        $this->assertNull($a['sponsor_name']);
        $this->assertNull($a['eligibility_criteria']);
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['interventions']);
    }

    public function test_statuses_are_mapped(): void
    {
        $payload = self::payload(0);

        foreach ([1 => null, 2 => 'COMPLETED', 3 => 'NOT_YET_RECRUITING', 9 => null] as $statusId => $expected) {
            $payload['protocol']['status_id'] = $statusId;
            $this->assertSame($expected, $this->map($payload)->attributes['overall_status'], (string) $statusId);
        }
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        $mapper = new HealthCanadaMapper;
        $untitled = self::payload(0);
        $untitled['protocol']['protocol_title'] = ' ';

        foreach ([[], ['protocol' => ['protocol_id' => 0, 'protocol_title' => 'T']], ['protocol' => ['protocol_title' => 'T']], $untitled] as $payload) {
            try {
                $mapper->map($payload);
                $this->fail('Expected InvalidStudyPayload.');
            } catch (InvalidStudyPayload) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('37653', $mapper->payloadId(self::payload(1)));
        $this->assertNull($mapper->payloadId([]));
    }

    public function test_the_lists_are_parsed_and_validated(): void
    {
        $this->assertCount(3, HealthCanadaClient::parseProtocols(self::fixture('protocol_list.json')));
        $this->assertSame([], HealthCanadaClient::parseProtocols(self::fixture('empty.json')));

        $drugs = HealthCanadaClient::parseDrugProducts(self::fixture('drugproduct_list.json'));
        $this->assertSame([37653, 29420], array_keys($drugs), 'The all-zero object is ignored.');

        foreach (['', '<html></html>', '{"protocol_id": 1}', '[{"brand_id": 1}]'] as $body) {
            try {
                HealthCanadaClient::parseProtocols($body);
                $this->fail('Expected RegistryRequestFailed.');
            } catch (RegistryRequestFailed) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(RegistryRequestFailed::class);
        HealthCanadaClient::parseDrugProducts('[{"protocol_id": 1}]');
    }
}
