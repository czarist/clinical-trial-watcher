<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\PpbKenya\PpbKenyaClient;
use App\Services\Registries\Sources\PpbKenya\PpbKenyaMapper;
use App\Services\Registries\Sources\PpbKenya\PpbKenyaSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PpbKenyaMapperTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/registries/ppb_kenya/'.$name);
    }

    /**
     * The payload the client builds: the list row, the list it came from and the record page.
     *
     * @param  array<string, mixed>  $row
     */
    private function map(array $row, string $view, string $status = 'approved'): MappedStudy
    {
        return (new PpbKenyaMapper)->map([...$row, 'list_status' => $status, 'url' => PpbKenyaSource::viewUrl($row['view_id']), 'html' => self::fixture($view)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(int $index): array
    {
        return PpbKenyaClient::parseListing(self::fixture('list_single.html'))['rows'][$index];
    }

    public function test_a_record_is_mapped_with_the_sites_of_its_list_row(): void
    {
        $study = $this->map(self::row(0), 'view_1660.html');
        $attributes = $study->attributes;

        $this->assertSame('ppb_kenya', $study->source);
        $this->assertSame('ECCT/26/06/01', $study->sourceId);
        $this->assertStringStartsWith('A Phase 1, Multi-Site, Double-Blinded, Placebo-Controlled Dose-Escalation Study', (string) $attributes['brief_title']);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $attributes['brief_title']));
        $this->assertNull($attributes['overall_status']);
        $this->assertSame('PHASE1', $attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $attributes['study_type']);
        $this->assertSame('2026-11-08', $attributes['study_first_post_date']);
        $this->assertSame('2026-04-27', $attributes['raw_json']['date_of_protocol']);
        $this->assertSame('SANDLY', $attributes['org_study_id']);
        $this->assertStringStartsWith('Leishmaniasis is a serious disease', (string) $attributes['brief_summary']);
        $this->assertStringStartsWith("Study objectives:\nPrimary Objective:", (string) $attributes['detailed_description']);
        $this->assertStringContainsString("\n\nAbstract:\nLeishmaniasis, a parasitic disease", (string) $attributes['detailed_description']);
        $this->assertNull($attributes['sponsor_name']);

        $this->assertSame([
            ['facility' => 'Kenya Medical Research Institute Walter Reed Project', 'city' => null, 'state' => 'Kericho', 'country' => 'Kenya', 'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null],
            ['facility' => 'KEMRI Butere Clinical Research Centre', 'city' => null, 'state' => 'Kakamega', 'country' => 'Kenya', 'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null],
        ], $study->relations['locations']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame(ClinicalTrial::SYNCED_RELATIONS, array_keys($study->relations));

        $this->assertSame('html', $study->raw?->format);
        $this->assertSame('https://ctr.pharmacyboardkenya.org/applications/view/1660', $study->raw->url);
        $this->assertStringContainsString('ECCT/26/06/01', $study->raw->content);
        $this->assertCount(2, $study->facts?->sites ?? []);
    }

    public function test_investigators_are_never_read(): void
    {
        $study = $this->map(self::row(0), 'view_1660.html');
        $encoded = json_encode([$study->attributes, $study->relations], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Redacted', $encoded);
        $this->assertStringNotContainsString('Investigator', $encoded);
    }

    public function test_a_record_without_protocol_number_or_optional_fields(): void
    {
        $study = $this->map(self::row(1), 'view_1700.html');
        $attributes = $study->attributes;

        $this->assertSame('view-1700', $study->sourceId);
        $this->assertSame('Hibiscus tea for blood pressure in adults', $attributes['brief_title']);
        $this->assertSame('SUSPENDED', $attributes['overall_status']);
        $this->assertSame('2024-01-15', $attributes['study_first_post_date']);

        foreach (['phase', 'brief_summary', 'detailed_description', 'start_date', 'last_update_post_date'] as $column) {
            $this->assertNull($attributes[$column], $column);
        }

        $this->assertSame([], $study->relations['locations']);
        $this->assertSame(array_keys($this->map(self::row(0), 'view_1660.html')->attributes), array_keys($attributes));
    }

    public function test_statuses_and_title_phases_are_mapped(): void
    {
        $row = ['view_id' => 1, 'protocol_no' => 'ECCT/20/01/01', 'title' => 'x', 'sites' => []];
        $mapper = new PpbKenyaMapper;
        $study = fn (string $status, string $title): MappedStudy => $mapper->map([...$row, 'list_status' => $status, 'html' => '<div id="applicationPrintArea"><table><tr><td>Study Title:</td><td>'.$title.'</td></tr></table></div>']);

        $this->assertSame('TERMINATED', $study('stopped', 'A study')->attributes['overall_status']);
        $this->assertNull($study('approved', 'A study')->attributes['overall_status']);
        $this->assertSame('PHASE2, PHASE3', $study('approved', 'A Phase II/III trial')->attributes['phase']);
        $this->assertSame('PHASE2', $study('approved', 'Phase IIb study of X')->attributes['phase']);
        $this->assertSame('PHASE3', $study('approved', 'Randomized phase 3 trial')->attributes['phase']);
        $this->assertNull($study('approved', 'Phases of the moon')->attributes['phase']);
    }

    public function test_a_payload_without_identifier_or_page_is_rejected(): void
    {
        $this->assertSame('view-9', (new PpbKenyaMapper)->payloadId(['view_id' => 9]));

        $this->expectException(InvalidStudyPayload::class);
        (new PpbKenyaMapper)->map(['html' => '<div id="applicationPrintArea"><table><tr><td>Study Title:</td><td>A title</td></tr></table></div>']);
    }

    public function test_a_payload_without_html_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        (new PpbKenyaMapper)->map(['view_id' => 1660, 'protocol_no' => 'ECCT/26/06/01']);
    }

    public function test_list_pages_are_parsed_without_investigators(): void
    {
        $listing = PpbKenyaClient::parseListing(self::fixture('list_approved_p1.html'), 'text/html; charset=UTF-8');

        $this->assertSame(1, $listing['page']);
        $this->assertSame(59, $listing['pages']);
        $this->assertCount(10, $listing['rows']);
        $this->assertSame(['view_id' => 1660, 'protocol_no' => 'ECCT/26/06/01'], array_slice($listing['rows'][array_search(1660, array_column($listing['rows'], 'view_id'), true)], 0, 2));
        $dates = array_column($listing['rows'], 'approved_on');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'the list is read newest approval first');
        $this->assertStringNotContainsString('Redacted', json_encode($listing, JSON_THROW_ON_ERROR));

        $sites = array_merge(...array_column($listing['rows'], 'sites'));
        $this->assertContains(['name' => 'KEMRI-CRDR', 'county' => 'Nairobi City'], $sites);
        $this->assertContains(['name' => "Getrudes’s Children's Hospital", 'county' => null], $sites);

        $this->assertSame(['rows' => [], 'page' => 1, 'pages' => 1], PpbKenyaClient::parseListing(self::fixture('list_empty.html')));
    }

    public function test_a_page_that_is_not_a_list_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        PpbKenyaClient::parseListing('<html><body><h1>Site under maintenance</h1></body></html>', 'text/html');
    }

    public function test_a_list_with_a_non_html_content_type_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        PpbKenyaClient::parseListing(self::fixture('list_single.html'), 'application/pdf');
    }

    public function test_tokens_urls_and_access(): void
    {
        $this->assertSame([0, 1, 0], PpbKenyaClient::parseToken(null));
        $this->assertSame([1, 3, 4], PpbKenyaClient::parseToken('1:3:4'));
        $this->assertSame(
            'https://ctr.pharmacyboardkenya.org/applications/index/sort:approval_date/direction:desc/status:approved/page:2',
            PpbKenyaClient::listUrl('approved', 2),
        );
        $this->assertSame('2026-11-08', PpbKenyaClient::date('08-11-2026'));
        $this->assertNull(PpbKenyaClient::date('31-02-2026'));

        $source = (new \ReflectionClass(PpbKenyaSource::class))->newInstanceWithoutConstructor();
        $this->assertSame('https://ctr.pharmacyboardkenya.org/applications/view/1660', $source->recordUrl('view-1660'));
        $this->assertNull($source->recordUrl('ECCT/26/06/01'));
        $this->assertNull($source->recordUrl('view-1660/../edit'));
        $this->assertFalse($source->access()->commercialUse);

        $this->expectException(InvalidArgumentException::class);
        PpbKenyaClient::parseToken('2');
    }
}
