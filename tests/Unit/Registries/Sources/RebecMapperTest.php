<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\Rebec\RebecClient;
use App\Services\Registries\Sources\Rebec\RebecMapper;
use App\Services\Registries\Sources\Rebec\RebecSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RebecMapperTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/registries/rebec/'.$name);
    }

    private function map(string $fixture, string $id): MappedStudy
    {
        return (new RebecMapper)->map([
            'id' => $id,
            'url' => 'https://ensaiosclinicos.gov.br/rg/'.$id,
            'html' => self::fixture($fixture),
        ]);
    }

    public function test_a_trial_page_is_mapped_from_its_structured_data_and_labelled_blocks(): void
    {
        $study = $this->map('trial_RBR-10jdwz27.html', 'RBR-10jdwz27');
        $attributes = $study->attributes;

        $this->assertSame('rebec', $study->source);
        $this->assertSame('RBR-10jdwz27', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('Analysis of protein alterations and mental disorders in patients undergoing cardiac surgery', $attributes['brief_title']);
        $this->assertStringStartsWith('Assessment of mental health changes and proteomic analysis', (string) $attributes['official_title']);
        $this->assertSame('RECRUITING', $attributes['overall_status']);
        $this->assertSame('OBSERVATIONAL', $attributes['study_type']);
        $this->assertNull($attributes['phase']);
        $this->assertSame('2026-11-20', $attributes['study_first_post_date']);
        $this->assertSame('2026-11-20', $attributes['last_update_post_date']);
        $this->assertSame('2026-07-06', $attributes['start_date']);
        $this->assertSame(144, $attributes['enrollment_count']);
        $this->assertSame('Centro Universitário do Espirito Santo - UNESC', $attributes['sponsor_name']);
        $this->assertSame('ALL', $attributes['gender']);
        $this->assertSame('18 Years', $attributes['min_age']);
        $this->assertNull($attributes['max_age']);
        $this->assertStringStartsWith("Inclusion criteria:\nPatients over 18 years", (string) $attributes['eligibility_criteria']);
        $this->assertStringContainsString("\n\nExclusion criteria:\n", (string) $attributes['eligibility_criteria']);
        $this->assertFalse($attributes['has_results']);
        $this->assertSame(['R41', 'F32'], $attributes['raw_json']['page_map']['hc_code']);
        $this->assertSame('MedicalTrial', $attributes['raw_json']['json_ld']['@type']);

        $this->assertSame(
            [['condition' => 'depressive episodes'], ['condition' => 'Other specified symptoms and signs relating to cognitive functions and consciousness']],
            $study->relations['conditions'],
        );
        $this->assertSame([['keyword' => 'Observational Study']], $study->relations['keywords']);
        $this->assertCount(1, $study->relations['interventions']);
        $this->assertStringStartsWith('This project proposes a prospective cohort study', (string) $study->relations['interventions'][0]['description']);
        $this->assertSame('Brazil', $study->relations['locations'][0]['country']);
        $this->assertCount(1, $study->relations['locations']);
        $this->assertSame([5, 5], [
            count(array_filter($study->relations['outcomes'], fn (array $row): bool => $row['type'] === 'PRIMARY')),
            count(array_filter($study->relations['outcomes'], fn (array $row): bool => $row['type'] === 'SECONDARY')),
        ]);
        $this->assertSame([['name' => 'Centro Universitário do Espirito Santo - UNESC', 'class' => null]], $study->relations['collaborators']);
        $this->assertSame(ClinicalTrial::SYNCED_RELATIONS, array_keys($study->relations));
    }

    public function test_registry_identifiers_are_secondary_ids_and_ethics_numbers_are_typed(): void
    {
        $ids = $this->map('trial_RBR-10jdwz27.html', 'RBR-10jdwz27')->relations['secondaryIds'];

        $this->assertSame(['U1111-1349-7287', '97887926510015062', '8.490.993'], array_column($ids, 'secondary_id'));
        $this->assertSame(['UTN', 'CAAE'], [$ids[0]['type'], $ids[1]['type']]);
    }

    public function test_the_stored_page_keeps_the_record_but_not_the_contacts(): void
    {
        $study = $this->map('trial_RBR-10jdwz27.html', 'RBR-10jdwz27');

        $this->assertSame('html', $study->raw?->format);
        $this->assertSame('https://ensaiosclinicos.gov.br/rg/RBR-10jdwz27', $study->raw->url);
        $this->assertStringContainsString('U1111-1349-7287', $study->raw->content);
        $this->assertStringNotContainsString('class="vcard"', $study->raw->content);

        $minimal = $this->map('trial_RBR-7b6xk3m.html', 'RBR-7b6xk3m')->raw?->content;
        $this->assertStringNotContainsString('Jane Example', (string) $minimal);
        $this->assertStringNotContainsString('jane@example.org', (string) $minimal);
        $this->assertStringContainsString('Additional links', (string) $minimal);
    }

    public function test_missing_optional_fields_are_null_and_html_values_are_used_without_structured_data(): void
    {
        $study = $this->map('trial_RBR-7b6xk3m.html', 'RBR-7b6xk3m');
        $attributes = $study->attributes;

        $this->assertSame('RBR-7b6xk3m', $study->sourceId);
        $this->assertSame('Exercise after stroke', $attributes['brief_title']);
        $this->assertSame('Exercício físico após acidente vascular cerebral: ensaio clínico randomizado', $attributes['official_title']);
        $this->assertSame('ACTIVE_NOT_RECRUITING', $attributes['overall_status']);
        $this->assertSame('INTERVENTIONAL', $attributes['study_type']);
        $this->assertSame('PHASE2, PHASE3', $attributes['phase']);
        $this->assertSame('2023-01-10', $attributes['study_first_post_date']);
        $this->assertSame('2024-03-15', $attributes['last_update_post_date']);
        $this->assertSame(['FEMALE', '18 Years', '65 Years'], [$attributes['gender'], $attributes['min_age'], $attributes['max_age']]);

        foreach (['start_date', 'sponsor_name', 'enrollment_count', 'eligibility_criteria', 'acronym', 'brief_summary'] as $column) {
            $this->assertNull($attributes[$column], $column);
        }

        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['locations']);
        $this->assertSame([], $study->relations['outcomes']);
        $this->assertSame([['secondary_id' => 'NCT01234567', 'type' => 'ClinicalTrials.gov', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $this->assertSame(array_keys($this->map('trial_RBR-10jdwz27.html', 'RBR-10jdwz27')->attributes), array_keys($attributes));
    }

    public function test_a_payload_without_a_page_or_identifier_is_rejected(): void
    {
        $this->assertSame('RBR-7b6xk3m', (new RebecMapper)->payloadId(['id' => 'RBR-7b6xk3m']));

        $this->expectException(InvalidStudyPayload::class);
        (new RebecMapper)->map(['id' => 'nothing', 'html' => '<html><body><div class="mainContent"><h2>Untitled</h2></div></body></html>']);
    }

    public function test_a_payload_without_html_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);
        (new RebecMapper)->map(['id' => 'RBR-7b6xk3m']);
    }

    public function test_list_pages_are_parsed_into_rows_and_the_last_page(): void
    {
        $listing = RebecClient::parseListing(self::fixture('list_1.html'), 'text/html; charset=UTF-8');

        $this->assertCount(20, $listing['rows']);
        $this->assertSame(495, $listing['lastPage']);
        $this->assertSame(
            ['id' => 'RBR-34bs2hf', 'title' => 'Vasodilator Supplementation in strength training', 'registered_on' => '2026-11-20', 'approved_on' => '2026-11-20', 'revision' => 1],
            $listing['rows'][0],
        );

        $single = RebecClient::parseListing(self::fixture('list_single.html'));
        $this->assertSame(['RBR-10jdwz27', 'RBR-7b6xk3m'], array_column($single['rows'], 'id'));
        $this->assertSame(['2023-01-10', '2024-03-15', 3], [$single['rows'][1]['registered_on'], $single['rows'][1]['approved_on'], $single['rows'][1]['revision']]);
        $this->assertSame(1, $single['lastPage']);

        $this->assertSame(['rows' => [], 'lastPage' => 1], RebecClient::parseListing(self::fixture('list_empty.html')));
    }

    public function test_a_page_that_is_not_a_listing_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        RebecClient::parseListing('<html><body><h1>Em manutenção</h1></body></html>', 'text/html');
    }

    public function test_a_listing_with_a_non_html_content_type_is_rejected(): void
    {
        $this->expectException(RegistryRequestFailed::class);
        RebecClient::parseListing(self::fixture('list_1.html'), 'application/json');
    }

    public function test_page_tokens_and_record_urls(): void
    {
        $this->assertSame([1, 0], RebecClient::parseToken(null));
        $this->assertSame([12, 5], RebecClient::parseToken('12:5'));

        $source = (new \ReflectionClass(RebecSource::class))->newInstanceWithoutConstructor();
        $this->assertSame('https://ensaiosclinicos.gov.br/rg/RBR-10jdwz27', $source->recordUrl('RBR-10jdwz27'));
        $this->assertNull($source->recordUrl('RBR-10jdwz27/../xml_ictrp'));
        $this->assertNull($source->recordUrl('rbr-10jdwz27'));
        $this->assertFalse($source->access()->commercialUse);

        $this->expectException(InvalidArgumentException::class);
        RebecClient::parseToken('page-two');
    }
}
