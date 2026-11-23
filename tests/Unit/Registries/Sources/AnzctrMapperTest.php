<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\Anzctr\AnzctrClient;
use App\Services\Registries\Sources\Anzctr\AnzctrMapper;
use App\Services\Registries\Sources\Anzctr\AnzctrSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnzctrMapperTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/registries/anzctr/'.$name);
    }

    private function map(string $fixture, ?string $url = null): MappedStudy
    {
        return (new AnzctrMapper)->map(['id' => null, 'url' => $url, 'updated_on' => null, 'html' => self::fixture($fixture)]);
    }

    public function test_a_trial_page_is_mapped_from_its_field_elements(): void
    {
        $study = $this->map('record_ACTRN12622000561785.html');
        $attributes = $study->attributes;

        $this->assertSame('anzctr', $study->source);
        $this->assertSame('ACTRN12622000561785', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('Effect of Immersive Virtual Reality on Touch Perception in People with Discomplete Paraplegia', $attributes['brief_title']);
        $this->assertStringStartsWith('RESTORE: A Pilot Single-arm Study', (string) $attributes['official_title']);
        $this->assertSame('RESTORE', $attributes['acronym']);
        $this->assertSame('RECRUITING', $attributes['overall_status']);
        $this->assertSame('INTERVENTIONAL', $attributes['study_type']);
        $this->assertSame('NA', $attributes['phase']);
        $this->assertSame('2026-01-28', $attributes['start_date']);
        $this->assertNull($attributes['primary_completion_date']);
        $this->assertSame('2029-11-30', $attributes['completion_date']);
        $this->assertSame('2022-04-12', $attributes['study_first_post_date']);
        $this->assertSame('2026-08-25', $attributes['last_update_post_date']);
        $this->assertSame(36, $attributes['enrollment_count']);
        $this->assertSame('ESTIMATED', $attributes['enrollment_type']);
        $this->assertSame('UNSW Sydney', $attributes['sponsor_name']);
        $this->assertSame('18 Years', $attributes['min_age']);
        $this->assertNull($attributes['max_age']);
        $this->assertSame('ALL', $attributes['gender']);
        $this->assertStringStartsWith("Inclusion criteria:\nThe study will recruit individuals", (string) $attributes['eligibility_criteria']);
        $this->assertStringContainsString("\n\nExclusion criteria:\na) incompatible for MRI", (string) $attributes['eligibility_criteria']);
        $this->assertStringStartsWith('Complete spinal cord injury (SCI) is associated', (string) $attributes['brief_summary']);
        $this->assertStringContainsString("\nThis became the first objective evidence", (string) $attributes['brief_summary']);
        $this->assertSame('No', $attributes['raw_json']['healthy_volunteers']);
        $this->assertSame('2022-03-23', $attributes['raw_json']['date_submitted']);
        $this->assertFalse($attributes['has_results']);

        $this->assertSame([['condition' => 'Spinal cord injury'], ['condition' => 'discomplete paraplegia']], $study->relations['conditions']);
        $this->assertSame(
            ['Injuries and Accidents', 'Neurological', 'Other injuries and accidents', 'Other neurological disorders'],
            array_column($study->relations['keywords'], 'keyword'),
        );
        $this->assertCount(1, $study->relations['interventions']);
        $this->assertSame('Treatment: Devices, Rehabilitation', $study->relations['interventions'][0]['type']);
        $this->assertStringStartsWith('This is a single-arm pilot treatment trial.', (string) $study->relations['interventions'][0]['description']);
        $this->assertSame(['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'WA', 'VIC'], array_column($study->relations['locations'], 'state'));
        $this->assertSame(['Australia'], array_values(array_unique(array_column($study->relations['locations'], 'country'))));
        $this->assertSame([1, 5], [
            count(array_filter($study->relations['outcomes'], fn (array $row): bool => $row['type'] === 'PRIMARY')),
            count(array_filter($study->relations['outcomes'], fn (array $row): bool => $row['type'] === 'SECONDARY')),
        ]);
        $this->assertStringStartsWith('At intervention completion', (string) $study->relations['outcomes'][0]['time_frame']);
        $this->assertSame([], $study->relations['collaborators']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame(ClinicalTrial::SYNCED_RELATIONS, array_keys($study->relations));
    }

    public function test_contacts_are_never_mapped_or_stored(): void
    {
        $study = $this->map('record_ACTRN12622000561785.html');

        $this->assertNotNull($study->raw);
        $this->assertSame('html', $study->raw->format);
        $this->assertSame('https://www.anzctr.org.au/Trial/Registration/TrialReview.aspx?ACTRN=12622000561785', $study->raw->url);
        $this->assertStringNotContainsString('Redacted', json_encode([$study->attributes, $study->relations], JSON_THROW_ON_ERROR));

        $cleaned = AnzctrMapper::withoutPersonalData(
            '<span id="ctl00_body_repeater_TXCONTACT0_ctl00_CXNAME">Dr Jane Roe</span><span id="ctl00_body_repeater_TXCONTACT0_ctl00_CXPHONE">+61 400</span>'
            .'<a class="__cf_email__" data-cfemail="95e6bb">x</a> jane@example.org',
        );
        $this->assertSame(
            '<span id="ctl00_body_repeater_TXCONTACT0_ctl00_CXNAME">Redacted</span><span id="ctl00_body_repeater_TXCONTACT0_ctl00_CXPHONE">Redacted</span>'
            .'<a class="__cf_email__" data-cfemail="">x</a> redacted@example.invalid',
            $cleaned,
        );
    }

    public function test_registry_identifiers_are_secondary_ids_and_related_trials_are_not(): void
    {
        $study = $this->map('synthetic_record_minimal.html');

        $this->assertSame([
            ['secondary_id' => 'NCT01234567', 'type' => null, 'domain' => null, 'link' => null],
            ['secondary_id' => 'Sponsor protocol HIP-01', 'type' => null, 'domain' => null, 'link' => null],
            ['secondary_id' => 'U1111-1234-5678', 'type' => 'UTN', 'domain' => null, 'link' => null],
        ], $study->relations['secondaryIds']);
        $this->assertSame('ACTRN12620000999999', $study->attributes['raw_json']['related_trials']);

        $keys = array_map(fn ($identifier): string => $identifier->key(), (new IdentifierParser)->fromStudy($study));
        $this->assertEqualsCanonicalizing(['actrn:ACTRN12625000123456', 'nct:NCT01234567', 'utn:U1111-1234-5678'], $keys);
    }

    public function test_missing_optional_fields_are_null_and_statuses_and_phases_are_mapped(): void
    {
        $attributes = $this->map('synthetic_record_minimal.html')->attributes;

        $this->assertSame('ACTRN12625000123456', $this->map('synthetic_record_minimal.html')->sourceId);
        $this->assertSame('A study of exercise after hip surgery', $attributes['brief_title']);
        $this->assertSame('TERMINATED', $attributes['overall_status']);
        $this->assertSame('PHASE2, PHASE3', $attributes['phase']);
        $this->assertSame('2025-02-03', $attributes['study_first_post_date']);

        foreach (['official_title', 'acronym', 'start_date', 'completion_date', 'last_update_post_date', 'brief_summary', 'study_type', 'enrollment_count', 'enrollment_type', 'sponsor_name', 'eligibility_criteria', 'min_age', 'max_age', 'gender'] as $column) {
            $this->assertNull($attributes[$column], $column);
        }
    }

    public function test_status_vocabulary(): void
    {
        $statuses = [
            'Not yet recruiting' => 'NOT_YET_RECRUITING',
            'Active, not recruiting' => 'ACTIVE_NOT_RECRUITING',
            'Closed: follow-up continuing' => 'ACTIVE_NOT_RECRUITING',
            'Closed: follow-up complete' => 'COMPLETED',
            'Completed' => 'COMPLETED',
            'Suspended' => 'SUSPENDED',
            'Withdrawn' => 'WITHDRAWN',
            'Something new' => null,
        ];
        $html = self::fixture('synthetic_record_minimal.html');

        foreach ($statuses as $label => $expected) {
            $page = str_replace('>Stopped early<', '>'.$label.'<', $html);
            $this->assertSame($expected, (new AnzctrMapper)->map(['html' => $page])->attributes['overall_status'], $label);
        }
    }

    public function test_a_page_without_an_actrn_number_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        (new AnzctrMapper)->map(['html' => str_replace('ACTRN12625000123456', 'pending', self::fixture('synthetic_record_minimal.html'))]);
    }

    public function test_an_empty_payload_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        (new AnzctrMapper)->map(['id' => 'ACTRN12625000123456']);
    }

    public function test_dates_are_read_day_first(): void
    {
        $this->assertSame('2025-08-01', AnzctrMapper::dayFirstDate('1/08/2025'));
        $this->assertSame('2026-08-25', AnzctrMapper::dayFirstDate('25/08/2026'));
        $this->assertNull(AnzctrMapper::dayFirstDate('31/02/2026'));
        $this->assertNull(AnzctrMapper::dayFirstDate(''));
    }

    public function test_actrn_numbers_are_canonical_and_record_urls_need_a_valid_one(): void
    {
        $this->assertSame('ACTRN12622000561785', AnzctrSource::canonicalId('actrn 12622000561785'));
        $this->assertNull(AnzctrSource::canonicalId('12622000561785'));

        $source = (new \ReflectionClass(AnzctrSource::class))->newInstanceWithoutConstructor();
        $this->assertSame('https://www.anzctr.org.au/Trial/Registration/TrialReview.aspx?ACTRN=12622000561785', $source->recordUrl('ACTRN12622000561785'));
        $this->assertNull($source->recordUrl('actrn12622000561785'));
        $this->assertNull($source->recordUrl('ACTRN1262200056178'));
    }

    public function test_the_listing_yields_linked_trial_numbers_once(): void
    {
        $this->assertSame(['ACTRN12622000561785', 'ACTRN12625000123456'], AnzctrClient::parseListing(self::fixture('synthetic_search.html'), 'text/html'));
        $this->assertSame([], AnzctrClient::parseListing(self::fixture('synthetic_search_empty.html')));
    }

    public function test_a_page_that_is_not_an_anzctr_page_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        AnzctrClient::parseListing('<html><head><title>Error</title></head><body></body></html>', 'text/html');
    }

    public function test_page_tokens_are_validated(): void
    {
        $this->assertSame(0, AnzctrClient::parseToken(null));
        $this->assertSame(12, AnzctrClient::parseToken('12'));

        $this->expectException(InvalidArgumentException::class);
        AnzctrClient::parseToken('1:2');
    }
}
