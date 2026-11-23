<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Parsing\CsvReader;
use App\Services\Registries\Sources\Tfda\TfdaClient;
use App\Services\Registries\Sources\Tfda\TfdaMapper;
use PHPUnit\Framework\TestCase;

final class TfdaMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/tfda/';

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function temporaryPath(): string
    {
        return $this->temporary[] = (string) tempnam(sys_get_temp_dir(), 'tfda-test-');
    }

    /**
     * Payloads as the client builds them: the export's rows grouped by TFDA document number.
     *
     * @return array<string, array<string, mixed>>
     */
    private function payloads(string $zip = 'export_205.zip'): array
    {
        $csv = $this->temporaryPath();
        TfdaClient::extractCsv(self::FIXTURES.$zip, $csv);
        $header = TfdaClient::header($csv);
        $payloads = [];

        foreach ((new CsvReader)->rows($csv) as $row) {
            $id = (string) $row[TfdaClient::ID_COLUMN];
            $payloads[$id] ??= ['document_number' => $id, 'header' => $header, 'rows' => [], 'url' => TfdaClient::DEFAULT_URL];
            $payloads[$id]['rows'][] = $row;
        }

        return $payloads;
    }

    public function test_rows_of_one_trial_are_grouped_into_one_record(): void
    {
        $payloads = $this->payloads();

        $this->assertCount(6, $payloads);
        $this->assertCount(2, $payloads['1119014005']['rows']);
    }

    public function test_a_trial_is_mapped_from_its_latest_row(): void
    {
        $study = (new TfdaMapper)->map($this->payloads()['1119014005']);

        $this->assertSame('tfda', $study->source);
        $this->assertSame('1119014005', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertStringStartsWith('一項為期2 年、隨機分配、3 試驗組', $study->attributes['brief_title']);
        $this->assertSame('CBAF312D2301', $study->attributes['org_study_id']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2021-12-01', $study->attributes['start_date']);
        $this->assertSame('2029-08-30', $study->attributes['completion_date']);
        $this->assertSame('2026-01-15', $study->attributes['last_update_post_date']);
        $this->assertSame(120, $study->attributes['enrollment_count']);
        $this->assertSame('ESTIMATED', $study->attributes['enrollment_type']);
        $this->assertSame('台灣諾華股份有限公司', $study->attributes['sponsor_name']);
        $this->assertStringStartsWith("納入條件：\n", $study->attributes['eligibility_criteria']);
        $this->assertStringContainsString("排除條件：\n", $study->attributes['eligibility_criteria']);

        $this->assertSame([['condition' => '多發性硬化症'], ['condition' => '小兒多發性硬化症']], $study->relations['conditions']);
        $this->assertSame('Taiwan', $study->relations['locations'][0]['country']);
        $this->assertSame('PRIMARY', $study->relations['outcomes'][0]['type']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['interventions']);

        $this->assertSame(['date' => '2021-12-01', 'type' => null], $study->facts?->dates['start_date']);
        $this->assertSame('csv', $study->raw?->format);
        $this->assertSame(TfdaClient::DEFAULT_URL, $study->raw->url);
        $this->assertSame(2, substr_count($study->raw->content, '1119014005'));
        $this->assertStringContainsString('TFDA收文號', $study->raw->content);
        $this->assertStringContainsString('2026/01/15', $study->raw->content);
    }

    public function test_combined_and_roman_phases(): void
    {
        $payload = $this->payloads()['1086006668'];
        $this->assertSame('PHASE1', (new TfdaMapper)->map($payload)->attributes['phase']);

        $payload['rows'][0]['臨床試驗期別'] = 'Phase Ⅱ,Phase Ⅲ';
        $this->assertSame('PHASE2, PHASE3', (new TfdaMapper)->map($payload)->attributes['phase']);
    }

    public function test_placeholders_and_missing_fields_become_null(): void
    {
        $study = (new TfdaMapper)->map($this->payloads()['1149000001']);

        $this->assertSame('1149000001', $study->sourceId);
        $this->assertSame('TEST-SPARSE-01', $study->attributes['org_study_id']);
        $this->assertNull($study->attributes['phase']);
        $this->assertNull($study->attributes['sponsor_name']);
        $this->assertNull($study->attributes['start_date']);
        $this->assertNull($study->attributes['completion_date']);
        $this->assertNull($study->attributes['enrollment_count']);
        $this->assertNull($study->attributes['enrollment_type']);
        $this->assertNull($study->attributes['brief_summary']);
        $this->assertNull($study->attributes['eligibility_criteria']);
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['outcomes']);
        $this->assertSame('2026-10-01', $study->attributes['last_update_post_date']);
    }

    public function test_every_record_has_the_same_attribute_keys(): void
    {
        $keys = array_map(fn (array $payload): array => array_keys((new TfdaMapper)->map($payload)->attributes), array_values($this->payloads()));

        $this->assertCount(1, array_unique(array_map('serialize', $keys)));
    }

    public function test_a_record_without_document_number_is_rejected(): void
    {
        $payload = $this->payloads()['1139089320'];
        $payload['document_number'] = null;

        $this->assertNull((new TfdaMapper)->payloadId($payload));
        $this->expectException(InvalidStudyPayload::class);
        (new TfdaMapper)->map($payload);
    }

    public function test_an_empty_export_has_no_records(): void
    {
        $this->assertSame([], $this->payloads('export_205_empty.zip'));
    }

    public function test_a_changed_header_is_refused(): void
    {
        $csv = $this->temporaryPath();
        TfdaClient::extractCsv(self::FIXTURES.'export_205_changed_header.zip', $csv);

        $this->expectException(RegistryRequestFailed::class);
        TfdaClient::header($csv);
    }

    public function test_a_truncated_archive_is_refused(): void
    {
        $zip = $this->temporaryPath();
        $bytes = (string) file_get_contents(self::FIXTURES.'export_205.zip');
        file_put_contents($zip, substr($bytes, 0, intdiv(strlen($bytes), 2)));

        $this->expectException(RegistryRequestFailed::class);
        $this->expectExceptionMessage('cut short');
        TfdaClient::extractCsv($zip, $this->temporaryPath());
    }

    public function test_a_corrupted_entry_fails_the_crc_check(): void
    {
        $zip = $this->temporaryPath();
        $archive = new \ZipArchive;
        $archive->open($zip, \ZipArchive::OVERWRITE);
        $archive->addFromString('205_2.csv', 'header');
        $archive->setCompressionName('205_2.csv', \ZipArchive::CM_STORE);
        $archive->close();
        // Flip the stored data while keeping the archive structure (and the recorded CRC).
        $bytes = (string) file_get_contents($zip);
        file_put_contents($zip, str_replace('header', 'HEADER', $bytes));

        $this->expectException(RegistryRequestFailed::class);
        TfdaClient::extractCsv($zip, $this->temporaryPath());
    }
}
