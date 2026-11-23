<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\Sukl\SuklClient;
use App\Services\Registries\Sources\Sukl\SuklMapper;
use App\Services\Registries\Sources\Sukl\SuklSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SuklMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/sukl/';

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_a_trial_detail_is_mapped(): void
    {
        $study = (new SuklMapper)->map($this->payload('detail_2014-004438-24.json'));

        $this->assertSame(SuklSource::KEY, $study->source);
        $this->assertSame('2014-004438-24', $study->sourceId);
        $this->assertStringStartsWith('ABT-414 alone or ABT-414 plus temozolomide', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['official_title']);
        $this->assertSame('M14-483', $study->attributes['org_study_id']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertNull($study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2016-01-12', $study->attributes['start_date']);
        $this->assertNull($study->attributes['completion_date']);
        $this->assertStringStartsWith('AbbVie Deutschland', $study->attributes['sponsor_name']);
        $this->assertSame('ALL', $study->attributes['gender']);
        $this->assertStringContainsString('Elderly ( > 65 years)', $study->attributes['eligibility_criteria']);
        $this->assertStringContainsString('Approved by SÚKL: 2015-09-25', $study->attributes['detailed_description']);
        $this->assertStringContainsString('ethics committee: 2015-10-13', $study->attributes['detailed_description']);
        $this->assertFalse($study->attributes['has_results']);

        $this->assertSame([['condition' => 'glioblastoma multiforme']], $study->relations['conditions']);
        $this->assertSame([], $study->relations['keywords']);
        $this->assertSame([], $study->relations['interventions']);

        $this->assertCount(4, $study->relations['locations']);
        $first = $study->relations['locations'][0];
        $this->assertSame('Fakultní nemocnice Ostrava, Onkologická klinika', $first['facility']);
        $this->assertSame('Ostrava-Poruba', $first['city']);
        $this->assertSame('708 52', $first['zip']);
        $this->assertSame('Czechia', $first['country']);
        $this->assertSame('Masarykův onkologický ústav', $study->relations['locations'][1]['facility']);

        // The EudraCT number links the record to the EU CTR and CTIS records of the trial.
        $this->assertSame([['secondary_id' => '2014-004438-24', 'type' => 'EudraCT', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $keys = array_map(fn ($identifier): string => $identifier->key(), (new IdentifierParser)->fromStudy($study));
        $this->assertSame(['eudract:2014-004438-24'], $keys);

        // Investigators are not kept in the raw record.
        $this->assertSame('json', $study->raw?->format);
        $this->assertSame('https://prehledy.sukl.cz/pkh/detail/2014-004438-24/jazyk/en', $study->raw?->url);
        $this->assertStringNotContainsString('"nazev":"Redacted"', (string) $study->raw?->content);
        $this->assertStringContainsString('Žlutý kopec 7', (string) $study->raw?->content);
        $this->assertSame('2016-01-12', $study->facts?->toArray()['dates']['start_date']['date'] ?? null);
    }

    public function test_a_trial_with_an_end_date_and_a_short_address(): void
    {
        $study = (new SuklMapper)->map($this->payload('synthetic_detail_2004-000711-26.json'));

        $this->assertSame('2005-06-30', $study->attributes['completion_date']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertSame('FEMALE', $study->attributes['gender']);
        $this->assertSame([['keyword' => 'Diagnostic agents']], $study->relations['keywords']);
        $this->assertStringContainsString('Note: Trial ended in the Czech Republic.', $study->attributes['detailed_description']);

        $this->assertSame('Fakultní nemocnice Brno', $study->relations['locations'][0]['facility']);
        $this->assertSame('625 00', $study->relations['locations'][0]['zip']);
        $this->assertSame('Nemocnice Na Homolce', $study->relations['locations'][1]['facility']);
        $this->assertNull($study->relations['locations'][1]['city']);
    }

    public function test_missing_fields_map_to_null(): void
    {
        $study = (new SuklMapper)->map($this->payload('synthetic_detail_2014-000555-93.json'));

        $this->assertSame('2014-000555-93', $study->sourceId);
        $this->assertSame('Abatacept reversing subclinical Inflammation as measured by MRI in ACPA positive Arthralgia', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['org_study_id']);
        $this->assertNull($study->attributes['sponsor_name']);
        $this->assertNull($study->attributes['start_date']);
        $this->assertNull($study->attributes['eligibility_criteria']);
        $this->assertNull($study->attributes['gender']);
        $this->assertNull($study->attributes['detailed_description']);
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['locations']);
        $this->assertCount(1, $study->relations['secondaryIds']);
    }

    public function test_every_record_has_the_same_attribute_keys(): void
    {
        $mapper = new SuklMapper;

        $this->assertSame(
            array_keys($mapper->map($this->payload('detail_2014-004438-24.json'))->attributes),
            array_keys($mapper->map($this->payload('synthetic_detail_2014-000555-93.json'))->attributes),
        );
    }

    public function test_a_payload_without_a_valid_eudract_number_is_rejected(): void
    {
        $this->assertNull((new SuklMapper)->payloadId(['eudract' => 'M14-483']));

        $this->expectException(InvalidStudyPayload::class);
        (new SuklMapper)->map(['nazev' => 'No identifier']);
    }

    public function test_the_search_response_is_parsed(): void
    {
        $listing = SuklClient::parseListing((string) file_get_contents(self::FIXTURES.'vyhledej_page1.json'));

        $this->assertSame(['2014-000555-93', '2004-000711-26'], $listing['ids']);
        $this->assertSame(3, $listing['total']);
        $this->assertSame(['ids' => [], 'total' => 0], SuklClient::parseListing((string) file_get_contents(self::FIXTURES.'vyhledej_empty.json')));
    }

    public function test_a_changed_search_response_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        SuklClient::parseListing('{"results":[]}');
    }

    public function test_a_listed_row_without_a_eudract_number_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        SuklClient::parseListing('{"data":[{"nazev":"x"}],"celkem":1}');
    }

    public function test_page_tokens_round_trip(): void
    {
        $this->assertSame([3, 50], SuklClient::decodeToken(SuklClient::encodeToken(3, 50)));

        $this->expectException(InvalidArgumentException::class);
        SuklClient::decodeToken('1|50');
    }

    public function test_record_urls_are_built_only_for_eudract_numbers(): void
    {
        $source = new \ReflectionClass(SuklSource::class);
        $instance = $source->newInstanceWithoutConstructor();

        $this->assertSame('https://prehledy.sukl.cz/pkh/detail/2014-004438-24/jazyk/en', $instance->recordUrl('2014-004438-24'));
        $this->assertNull($instance->recordUrl('2024-513653-75-00'));
        $this->assertNull($instance->recordUrl('../admin'));
    }
}
