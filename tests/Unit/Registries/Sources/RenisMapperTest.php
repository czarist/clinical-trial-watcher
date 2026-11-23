<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Services\Registries\Parsing\XlsxReader;
use App\Services\Registries\Sources\Renis\RenisClient;
use App\Services\Registries\Sources\Renis\RenisMapper;
use App\Services\Registries\Sources\Renis\RenisSource;
use PHPUnit\Framework\TestCase;

final class RenisMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/renis/';

    private const string FILE_URL = 'https://datos.salud.gob.ar/dataset/5b7b2efb-111a-48eb-9476-c5871f5f8f11/resource/54372b28-a8ff-4f2e-a09f-7869b307ce56/download/base-renis-_23-columnas_hasta-agosto-2026.xlsx';

    /**
     * Rows of the fixture workbook keyed by ID_RENIS, as the client hands them to the mapper.
     *
     * @return array<string, array<string, string|null>>
     */
    private static function rows(): array
    {
        $rows = [];

        foreach ((new XlsxReader)->rows(self::FIXTURES.'renis.xlsx') as $row) {
            $rows[(string) $row['ID_RENIS']] = $row + [RenisMapper::FILE_URL_KEY => self::FILE_URL];
        }

        return $rows;
    }

    public function test_an_industry_trial_is_mapped_and_cites_its_nct_number(): void
    {
        $study = (new RenisMapper)->map(self::rows()['5466']);

        $this->assertSame(RenisSource::KEY, $study->source);
        $this->assertSame('5466', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertStringStartsWith('206867-Estudio de fase 3', (string) $study->attributes['brief_title']);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $study->attributes['brief_title']));
        $this->assertSame('RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('2024-08-05', $study->attributes['study_first_post_date']);
        $this->assertSame(292, $study->attributes['enrollment_count']);
        $this->assertSame('PPD Argentina S.A.', $study->attributes['sponsor_name']);
        $this->assertSame([['secondary_id' => 'NCT05757102', 'type' => 'NCT', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame([['condition' => 'Respiratorio'], ['condition' => 'Asma']], $study->relations['conditions']);
        $this->assertSame('Medicamento', $study->relations['interventions'][0]['type']);
        $this->assertStringStartsWith('Experimental: participantes', (string) $study->relations['interventions'][0]['description']);
        $this->assertSame('Argentina', $study->relations['locations'][0]['country']);
        $this->assertSame('RECRUITING', $study->facts?->overallStatus);

        $this->assertSame('json', $study->raw?->format);
        $this->assertSame(self::FILE_URL, $study->raw->url);
        $raw = json_decode($study->raw->content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('5466', $raw['ID_RENIS']);
        $this->assertArrayNotHasKey('MAIL', $raw);
        $this->assertArrayNotHasKey('RESPONSABLE_REGISTRO', $raw);
        $this->assertArrayNotHasKey(RenisMapper::FILE_URL_KEY, $raw);
    }

    public function test_statuses_and_eudract_numbers_are_mapped(): void
    {
        $rows = self::rows();
        $mapper = new RenisMapper;

        $suspended = $mapper->map($rows['362']);
        $this->assertSame('SUSPENDED', $suspended->attributes['overall_status']);
        $this->assertSame([['secondary_id' => '2012-001610-42', 'type' => 'EudraCT', 'domain' => null, 'link' => null]], $suspended->relations['secondaryIds']);

        $completed = $mapper->map($rows['2']);
        $this->assertSame('COMPLETED', $completed->attributes['overall_status']);
        $this->assertSame('NA', $completed->attributes['phase']);
        $this->assertTrue($completed->attributes['has_results']);
        $this->assertSame([], $completed->relations['secondaryIds']); // "NA"
        $this->assertSame('2012-08-09', $completed->attributes['study_first_post_date']);

        $expected = [
            'No iniciado aún' => 'NOT_YET_RECRUITING',
            'Activo no incorporando' => 'ACTIVE_NOT_RECRUITING',
            'Finalizado' => 'COMPLETED',
            'Otro estado' => null,
            '' => null, // empty cell
        ];

        foreach ($expected as $status => $mapped) {
            $row = $rows['2'];
            $row['ESTADO_RECLUTAMIENTO'] = $status === '' ? null : (string) $status;
            $this->assertSame($mapped, $mapper->map($row)->attributes['overall_status'], (string) $status);
        }
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $study = (new RenisMapper)->map(self::rows()['1']);

        $this->assertSame('OBSERVATIONAL', $study->attributes['study_type']);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->attributes['overall_status']);
        $this->assertNull($study->attributes['phase']);
        $this->assertNull($study->attributes['sponsor_name']);
        $this->assertNull($study->attributes['start_date']);
        $this->assertFalse($study->attributes['has_results']);
        $this->assertSame('2012-05-18', $study->attributes['study_first_post_date']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['interventions']);
        $this->assertSame([], $study->relations['collaborators']);
    }

    public function test_identifiers_are_read_from_free_text_and_junk_is_ignored(): void
    {
        $this->assertSame([['NCT01234567', 'NCT']], RenisMapper::citedIdentifiers('nct 01234567'));
        $this->assertSame([['2012-004072-19', 'EudraCT']], RenisMapper::citedIdentifiers('EudraCT2012-004072-19'));
        $this->assertSame([['2024-510730-40-00', 'EU CT']], RenisMapper::citedIdentifiers('2024-510730-40-00'));
        $this->assertSame([], RenisMapper::citedIdentifiers('EU CT: 2025-521242-26')); // incomplete EU CT number
        $this->assertSame(
            [['NCT01234567', 'NCT'], ['NCT07654321', 'NCT']],
            RenisMapper::citedIdentifiers('NCT01234567 / NCT07654321'),
        );

        foreach (['NA', 'N/A', 'x', '3523/2022', '2624', 'NCT', null] as $junk) {
            $this->assertSame([], RenisMapper::citedIdentifiers($junk), (string) $junk);
        }
    }

    public function test_a_row_without_a_valid_id_is_rejected(): void
    {
        $mapper = new RenisMapper;
        $this->assertNull($mapper->payloadId(['ID_RENIS' => 'IS-12']));
        $this->assertSame('12', $mapper->payloadId(['ID_RENIS' => '12']));

        $this->expectException(InvalidStudyPayload::class);
        $mapper->map(['ID_RENIS' => null, 'TITULO_COMPLETO_ENSAYO' => 'Sin identificador']);
    }

    public function test_the_newest_workbook_is_found_on_the_dataset_page(): void
    {
        $html = (string) file_get_contents(self::FIXTURES.'dataset.html');

        $this->assertSame(self::FILE_URL, RenisClient::newestWorkbookUrl($html));
        $this->assertNull(RenisClient::newestWorkbookUrl('<html><a href="https://evil.example/renis-2030.xlsx">x</a></html>'));
        $this->assertSame(
            'https://datos.salud.gob.ar/dataset/x/resource/y/download/renis.xlsx',
            RenisClient::newestWorkbookUrl('<html><a href="/dataset/x/resource/y/download/renis.xlsx">x</a></html>'),
        );
    }

    public function test_records_have_no_page_of_their_own(): void
    {
        $source = (new \ReflectionClass(RenisSource::class))->newInstanceWithoutConstructor();

        $this->assertNull($source->recordUrl('5466'));
    }
}
