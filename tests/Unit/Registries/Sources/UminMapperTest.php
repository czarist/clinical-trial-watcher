<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Parsing\CsvReader;
use App\Services\Registries\Sources\Umin\UminClient;
use App\Services\Registries\Sources\Umin\UminMapper;
use App\Services\Registries\Sources\Umin\UminSource;
use PHPUnit\Framework\TestCase;

/**
 * The dump fixture is synthetic: UMIN does not document its CSV columns and no real dump could be
 * read, so these tests check the header-driven mapping against the assumed column names only.
 */
final class UminMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/umin/';

    /**
     * @return array<string, array<string, string|null>> rows keyed by UMIN number
     */
    private static function rows(string $fixture = 'synthetic_ctr_data_e.csv.gz'): array
    {
        $rows = [];

        foreach ((new CsvReader)->rows('compress.zlib://'.self::FIXTURES.$fixture) as $row) {
            $rows[(string) $row['Unique ID issued by UMIN']] = $row;
        }

        return $rows;
    }

    public function test_a_full_row_is_mapped(): void
    {
        $study = (new UminMapper)->map(self::rows()['UMIN000012345']);

        $this->assertSame(UminSource::KEY, $study->source);
        $this->assertSame('UMIN000012345', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('Synthetic study of drug B for synthetic gastritis', $study->attributes['brief_title']);
        $this->assertStringStartsWith('A randomized synthetic trial', $study->attributes['official_title']);
        $this->assertSame('SYN-B', $study->attributes['acronym']);
        $this->assertSame('RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('PHASE2, PHASE3', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2023-11-15', $study->attributes['start_date']);
        $this->assertSame('2026-03-31', $study->attributes['completion_date']);
        $this->assertSame('2023-10-01', $study->attributes['study_first_post_date']);
        $this->assertSame('2026-11-01', $study->attributes['last_update_post_date']);
        $this->assertSame(120, $study->attributes['enrollment_count']);
        $this->assertSame('ESTIMATED', $study->attributes['enrollment_type']);
        $this->assertSame('Synthetic University Hospital', $study->attributes['sponsor_name']);
        $this->assertSame('20 Years', $study->attributes['min_age']);
        $this->assertSame('75 Years', $study->attributes['max_age']);
        $this->assertSame('ALL', $study->attributes['gender']);
        $this->assertSame("Inclusion criteria:\nAdults with synthetic gastritis\n\nExclusion criteria:\nPregnant women", $study->attributes['eligibility_criteria']);
        $this->assertSame('To evaluate the synthetic efficacy of drug B.', $study->attributes['brief_summary']);

        // NCT and jRCT numbers link the record to its other registrations.
        $this->assertSame([
            ['secondary_id' => 'NCT05123456', 'type' => 'NCT', 'domain' => null, 'link' => null],
            ['secondary_id' => 'jRCTs031230001', 'type' => 'JRCT', 'domain' => null, 'link' => null],
        ], $study->relations['secondaryIds']);
        $this->assertSame([['condition' => 'Synthetic gastritis']], $study->relations['conditions']);
        $this->assertSame([
            ['type' => null, 'name' => 'Drug B 10 mg daily', 'description' => "Drug B 10 mg daily\nfor 8 weeks"],
            ['type' => null, 'name' => 'Placebo daily for 8 weeks', 'description' => null],
        ], $study->relations['interventions']);
        $this->assertSame(['PRIMARY', 'SECONDARY'], array_column($study->relations['outcomes'], 'type'));
        $this->assertSame([], $study->relations['locations']);

        $this->assertSame('RECRUITING', $study->facts?->overallStatus);
        $this->assertSame('csv', $study->raw?->format);
        $this->assertSame(UminClient::DUMP_URL, $study->raw?->url);
        $this->assertStringContainsString('UMIN000012345', (string) $study->raw?->content);

        foreach (['Redacted', 'Email', 'TEL', 'principal investigator'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $study->raw?->content);
        }
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $study = (new UminMapper)->map(self::rows()['UMIN000067890']);

        $this->assertSame('Synthetic observational registry', $study->attributes['brief_title']);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('OBSERVATIONAL', $study->attributes['study_type']);
        $this->assertSame('2019-05-20', $study->attributes['last_update_post_date']);

        foreach (['official_title', 'acronym', 'phase', 'start_date', 'completion_date', 'study_first_post_date', 'enrollment_count',
            'sponsor_name', 'min_age', 'max_age', 'gender', 'eligibility_criteria', 'brief_summary'] as $column) {
            $this->assertNull($study->attributes[$column], $column);
        }

        foreach ($study->relations as $name => $rows) {
            $this->assertSame([], $rows, $name);
        }
    }

    public function test_the_own_umin_number_is_not_a_secondary_id_and_partial_dates_are_normalized(): void
    {
        $study = (new UminMapper)->map(self::rows()['UMIN000099999']);

        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame('COMPLETED', $study->attributes['overall_status']);
        $this->assertSame('NA', $study->attributes['phase']);
        $this->assertSame('FEMALE', $study->attributes['gender']);
        $this->assertSame('18 Years', $study->attributes['min_age']);
        $this->assertNull($study->attributes['max_age']);
        $this->assertSame('2015-02-01', $study->attributes['start_date']);
    }

    public function test_a_row_without_a_umin_number_is_rejected(): void
    {
        $mapper = new UminMapper;
        $this->assertNull($mapper->payloadId(['Unique ID issued by UMIN' => 'R000014444']));
        $this->assertSame('UMIN000012345', $mapper->payloadId(['unique id issued by umin' => ' UMIN000012345 ']));

        $this->expectException(InvalidStudyPayload::class);
        $mapper->map(['Public title' => 'No id']);
    }

    public function test_the_dump_header_is_checked(): void
    {
        $this->assertContains('Unique ID issued by UMIN', UminClient::readHeader(self::FIXTURES.'synthetic_ctr_data_e.csv.gz'));
        $this->assertContains('Unique ID issued by UMIN', UminClient::readHeader(self::FIXTURES.'synthetic_ctr_data_e_empty.csv.gz'));
        $this->assertSame([], self::rows('synthetic_ctr_data_e_empty.csv.gz'));
    }

    public function test_a_file_that_is_not_gzip_is_rejected(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'umin-test');
        file_put_contents($path, "Unique ID issued by UMIN,Public title\nUMIN000012345,Plain CSV\n");

        try {
            $this->expectException(RegistryRequestFailed::class);
            UminClient::readHeader($path);
        } finally {
            unlink($path);
        }
    }

    public function test_a_dump_with_other_columns_is_rejected(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'umin-test');
        file_put_contents($path, (string) gzencode("<html><body>Login</body></html>\n"));

        try {
            $this->expectException(RegistryRequestFailed::class);
            UminClient::readHeader($path);
        } finally {
            unlink($path);
        }
    }

    public function test_incremental_runs_keep_rows_modified_since_the_date(): void
    {
        $this->assertTrue(UminClient::modifiedSince(['Last modified on' => '2026/11/01'], '2026-11-01'));
        $this->assertFalse(UminClient::modifiedSince(['Last modified on' => '2019/05/20'], '2026-11-01'));
        $this->assertTrue(UminClient::modifiedSince(['Public title' => 'No date'], '2026-11-01'));
    }

    public function test_no_record_url_is_built(): void
    {
        $source = (new \ReflectionClass(UminSource::class))->newInstanceWithoutConstructor();

        $this->assertNull($source->recordUrl('UMIN000012345'));
    }
}
