<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\Hra\HraClient;
use App\Services\Registries\Sources\Hra\HraMapper;
use App\Services\Registries\Sources\Hra\HraSource;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HraMapperTest extends TestCase
{
    private const string URL = 'https://www.hra.nhs.uk/planning-and-improving-research/application-summaries/research-summaries/phase-3-study-of-treprostinil-inhalation-powder-in-pah-palm-pah/';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/registries/hra/'.$name);
    }

    private function map(string $fixture, ?string $id = null): MappedStudy
    {
        return (new HraMapper)->map(['id' => $id, 'url' => self::URL, 'rec_opinion_date' => null, 'html' => self::fixture($fixture)]);
    }

    public function test_a_research_summary_is_mapped_from_its_labelled_values(): void
    {
        $study = $this->map('detail_palm-pah.html', 'IRAS1013819');
        $attributes = $study->attributes;

        $this->assertSame('hra', $study->source);
        $this->assertSame('IRAS1013819', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('Phase 3 Study of Treprostinil Inhalation Powder in PAH (PALM-PAH)', $attributes['brief_title']);
        $this->assertStringStartsWith('A Phase 3, Randomized, Double-Blind, Placebo-controlled', (string) $attributes['official_title']);
        $this->assertSame('Insmed Incorporated', $attributes['sponsor_name']);
        $this->assertStringStartsWith('This clinical study is designed to evaluate a new treatment (TPIP)', (string) $attributes['brief_summary']);
        $this->assertStringContainsString("unbiased results.\nThe study will last up to 32 weeks", (string) $attributes['brief_summary']);
        $this->assertSame([
            'research_type' => 'Research Study',
            'rec_name' => 'South Central - Berkshire Research Ethics Committee',
            'rec_reference' => '26/SC/0166',
            'rec_opinion' => 'Further Information Favourable Opinion',
            'rec_opinion_date' => '2026-08-12',
        ], $attributes['raw_json']);
        $this->assertFalse($attributes['has_results']);
        $this->assertSame(ClinicalTrial::SYNCED_RELATIONS, array_keys($study->relations));
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['locations']);
    }

    public function test_the_rec_opinion_is_not_presented_as_a_trial_status_and_unpublished_fields_stay_empty(): void
    {
        $attributes = $this->map('detail_palm-pah.html')->attributes;

        foreach (['overall_status', 'study_type', 'phase', 'start_date', 'completion_date', 'study_first_post_date', 'last_update_post_date', 'enrollment_count', 'eligibility_criteria', 'gender'] as $column) {
            $this->assertNull($attributes[$column], $column);
        }
    }

    public function test_the_iras_number_is_a_secondary_id_that_links_isrctn_records(): void
    {
        $study = $this->map('detail_palm-pah.html');

        $this->assertSame([
            ['secondary_id' => 'IRAS1013819', 'type' => 'IRAS', 'domain' => null, 'link' => null],
            ['secondary_id' => '26/SC/0166', 'type' => 'REC reference', 'domain' => null, 'link' => null],
        ], $study->relations['secondaryIds']);

        $keys = array_map(fn ($identifier): string => $identifier->key(), (new IdentifierParser)->fromStudy($study));
        $this->assertSame(['iras:IRAS1013819'], $keys);
        // An ISRCTN record citing the digits as its IRAS number yields the same identifier.
        $this->assertSame('iras:IRAS1013819', (new IdentifierParser)->parse('1013819', 'IRAS')?->key());
    }

    public function test_contacts_are_never_mapped_or_stored(): void
    {
        $study = $this->map('detail_palm-pah.html');

        $this->assertNotNull($study->raw);
        $this->assertSame('html', $study->raw->format);
        $this->assertSame(self::URL, $study->raw->url);
        $this->assertStringNotContainsString('<h4>Contact email</h4><p><a', $study->raw->content);
        $this->assertStringContainsString('<h4>Contact name</h4><p>Redacted</p>', $study->raw->content);
        $this->assertStringNotContainsString('Redacted', json_encode($study->attributes, JSON_THROW_ON_ERROR));

        $cleaned = HraMapper::withoutPersonalData('<li class="research-summary-list__item"><h4>Contact email</h4><p><a href="mailto:jane@example.org">jane@example.org</a></p></li><p>write to bob@example.org</p>');
        $this->assertSame('<li class="research-summary-list__item"><h4>Contact email</h4><p>Redacted</p></li><p>write to redacted@example.invalid</p>', $cleaned);
    }

    public function test_a_deferred_summary_maps_with_missing_fields_as_null(): void
    {
        $study = (new HraMapper)->map(['id' => 'IRAS1014302', 'url' => null, 'rec_opinion_date' => '2026-10-02', 'html' => self::fixture('synthetic_detail_deferred.html')]);

        $this->assertSame('IRAS1014302', $study->sourceId);
        $this->assertSame('Phase I trial (QSC303813)', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['official_title']);
        $this->assertNull($study->attributes['sponsor_name']);
        $this->assertSame('Publication of this data is currently deferred.', $study->attributes['brief_summary']);
        $this->assertSame('2026-10-02', $study->attributes['raw_json']['rec_opinion_date']);
        $this->assertNull($study->raw?->url);
    }

    public function test_a_page_without_an_iras_number_or_without_html_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        (new HraMapper)->map(['id' => null, 'html' => '<html><body><div class="research-summary-list"><h1>Untitled</h1><ul></ul></div></body></html>']);
    }

    public function test_an_empty_payload_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        (new HraMapper)->map(['id' => 'IRAS1013819']);
    }

    public function test_iras_numbers_are_canonical_and_record_urls_need_a_valid_one(): void
    {
        $this->assertSame('IRAS1013819', HraSource::canonicalId('1013819'));
        $this->assertSame('IRAS1013819', HraSource::canonicalId('IRAS ID: 1013819'));
        $this->assertNull(HraSource::canonicalId('26/SC/0166'));
        $this->assertNull(HraSource::canonicalId('12'));

        $source = (new \ReflectionClass(HraSource::class))->newInstanceWithoutConstructor();
        $this->assertSame('https://www.hra.nhs.uk/planning-and-improving-research/application-summaries/research-summaries/?query=1013819', $source->recordUrl('IRAS1013819'));
        $this->assertNull($source->recordUrl('1013819'));
        $this->assertNull($source->recordUrl('../admin'));
    }

    public function test_the_listing_is_parsed_into_rows_and_the_last_page(): void
    {
        $listing = HraClient::parseListing(self::fixture('listing_page1.html'), 'text/html; charset=utf-8');

        $this->assertCount(20, $listing['rows']);
        $this->assertSame(3460, $listing['lastPage']);
        $this->assertSame([
            'path' => '/planning-and-improving-research/application-summaries/research-summaries/phase-i-trial-qsc303813/',
            'id' => 'IRAS1014302',
            'title' => 'Phase I trial (QSC303813)',
            'rec_opinion_date' => '2026-10-02',
            'rec_reference' => '26/NI/0093',
        ], $listing['rows'][0]);

        $single = HraClient::parseListing(self::fixture('listing_single.html'));
        $this->assertSame(['IRAS1014302', 'IRAS1013819'], array_column($single['rows'], 'id'));
        $this->assertSame(1, $single['lastPage']);

        $this->assertSame(['rows' => [], 'lastPage' => 1], HraClient::parseListing(self::fixture('synthetic_listing_empty.html')));
    }

    public function test_a_page_that_is_not_a_listing_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        HraClient::parseListing('<html><body><h1>Maintenance</h1></body></html>', 'text/html');
    }

    public function test_a_non_html_response_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        HraClient::parseListing(self::fixture('listing_page1.html'), 'application/json');
    }

    public function test_incremental_runs_filter_by_rec_opinion_date_with_a_lookback(): void
    {
        $this->assertSame(['page' => 2], HraClient::listingQuery(2, null));
        $this->assertSame(['page' => 1, 'date_from' => '03/07/2026'], HraClient::listingQuery(1, CarbonImmutable::parse('2026-10-31')));
    }

    public function test_page_tokens_are_validated(): void
    {
        $this->assertSame([1, 0], HraClient::parseToken(null));
        $this->assertSame([4, 7], HraClient::parseToken('4:7'));

        $this->expectException(InvalidArgumentException::class);
        HraClient::parseToken('0:1');
    }
}
