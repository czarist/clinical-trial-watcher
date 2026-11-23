<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\Reec\ReecClient;
use App\Services\Registries\Sources\Reec\ReecMapper;
use App\Services\Registries\Sources\Reec\ReecSource;
use PHPUnit\Framework\TestCase;

final class ReecMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/reec/';

    /**
     * A payload as the client builds it: the detail with its search row.
     *
     * @return array<string, mixed>
     */
    private function payload(string $id, ?string $search = null): array
    {
        $detail = json_decode((string) file_get_contents(self::FIXTURES."detalle_{$id}.json"), true, flags: JSON_THROW_ON_ERROR);

        return $detail + ['busqueda' => $search !== null ? ReecClient::parseSearch((string) file_get_contents(self::FIXTURES.$search), $id) : null];
    }

    public function test_a_study_detail_is_mapped(): void
    {
        $study = (new ReecMapper)->map($this->payload('2026-525328-10-00', 'busqueda_2026-525328-10-00.json'));

        $this->assertSame(ReecSource::KEY, $study->source);
        $this->assertSame('2026-525328-10-00', $study->sourceId);
        $this->assertStringStartsWith('A TWO-STAGE, SINGLE-ARM, PHASE II STUDY OF ZONGERTINIB', $study->attributes['brief_title']);
        $this->assertSame('MEDOPP0958', $study->attributes['org_study_id']);
        $this->assertSame('RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('PHASE2', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2026-09-30', $study->attributes['start_date']);
        $this->assertNull($study->attributes['completion_date']);
        $this->assertSame('2026-06-03', $study->attributes['study_first_post_date']);
        $this->assertSame('Medica Scientia Innovation Research S.L.', $study->attributes['sponsor_name']);
        $this->assertSame('To evaluate the investigator-assessed objective response rate (ORR) in all participants.', $study->attributes['brief_summary']);
        $this->assertStringStartsWith("Inclusion criteria:\nParticipant, or legal representative", $study->attributes['eligibility_criteria']);
        $this->assertFalse($study->attributes['has_results']);

        // HTML line breaks are removed from texts.
        $this->assertContains(['condition' => 'HR-positive/HER2-negative advanced breast cancer'], $study->relations['conditions']);
        $this->assertSame([['keyword' => 'Diseases [C] - Cancer [C04]']], $study->relations['keywords']);
        $this->assertSame('BI 1810631', $study->relations['interventions'][0]['name']);
        $this->assertStringContainsString('Route: ORAL', (string) $study->relations['interventions'][0]['description']);
        $this->assertSame('PRIMARY', $study->relations['outcomes'][0]['type']);
        $this->assertStringStartsWith('ORR, defined as', $study->relations['outcomes'][0]['measure']);

        $location = $study->relations['locations'][0];
        $this->assertSame('Fundacion Instituto Valenciano De Oncologia', $location['facility']);
        $this->assertSame('Valencia', $location['city']);
        $this->assertSame('Spain', $location['country']);

        // The EU CT number links the record to the CTIS record of the same trial.
        $this->assertSame([['secondary_id' => '2026-525328-10-00', 'type' => 'EU CT number', 'domain' => null, 'link' => null]], $study->relations['secondaryIds']);
        $keys = array_map(fn ($identifier): string => $identifier->key(), (new IdentifierParser)->fromStudy($study));
        $this->assertSame(['eu_ct:2026-525328-10-00'], $keys);
    }

    public function test_a_study_with_only_the_listing_fields_maps_missing_fields_to_null(): void
    {
        $study = (new ReecMapper)->map($this->payload('2025-525150-19-01'));

        $this->assertSame('2025-525150-19-01', $study->sourceId);
        // Without a title, the identifier is shown.
        $this->assertSame('2025-525150-19-01', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['official_title']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertNull($study->attributes['phase']);
        $this->assertNull($study->attributes['org_study_id']);
        $this->assertNull($study->attributes['eligibility_criteria']);
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['outcomes']);
        $this->assertNotEmpty($study->relations['locations']);
    }

    public function test_dated_events_set_the_status(): void
    {
        $payload = $this->payload('2026-525328-10-00', 'busqueda_2026-525328-10-00.json');

        $payload['calendario']['fechaFinRealGlobal'] = '01-10-2027';
        $this->assertSame('COMPLETED', (new ReecMapper)->map($payload)->attributes['overall_status']);
        $this->assertSame('2027-10-01', (new ReecMapper)->map($payload)->attributes['completion_date']);

        $payload['calendario']['fechaInterrupcion'] = '01-03-2027';
        $this->assertSame('SUSPENDED', (new ReecMapper)->map($payload)->attributes['overall_status']);

        $payload['calendario']['fechaFinPrematuro'] = '01-04-2027';
        $this->assertSame('TERMINATED', (new ReecMapper)->map($payload)->attributes['overall_status']);

        $payload = $this->payload('2026-525328-10-00', 'busqueda_2026-525328-10-00.json');
        $payload['busqueda']['estado'] = '9';
        $this->assertNull((new ReecMapper)->map($payload)->attributes['overall_status']);
    }

    public function test_personal_data_is_never_mapped_nor_kept_in_the_raw_record(): void
    {
        $payload = $this->payload('2026-525328-10-00');
        $payload['organismo']['mail'] = 'contact@example.org';
        $payload['organismo']['personaContacto'] = 'Jane Doe';
        $payload['centros']['centro'][0]['investigador'] = 'John Roe';
        $study = (new ReecMapper)->map($payload);

        $this->assertNotNull($study->raw);
        $this->assertSame('json', $study->raw->format);
        $this->assertSame('https://reec.aemps.es/reec-services/json/detalle/2026-525328-10-00', $study->raw->url);

        foreach (['contact@example.org', 'Jane Doe', 'John Roe', '"investigador"', '"personaContacto"', '"mail"', '"telefono"'] as $personal) {
            $this->assertStringNotContainsString($personal, $study->raw->content);
            $this->assertStringNotContainsString($personal, json_encode([$study->attributes, $study->relations], JSON_THROW_ON_ERROR));
        }
    }

    public function test_a_payload_without_an_identifier_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);

        (new ReecMapper)->map(['identificador' => '  ']);
    }

    public function test_listings_are_validated(): void
    {
        $this->assertSame(['2026-525328-10-00', '2025-525150-19-01'], ReecClient::parseListing((string) file_get_contents(self::FIXTURES.'estudios.json')));
        $this->assertSame([], ReecClient::parseListing((string) file_get_contents(self::FIXTURES.'estudios_empty.json')));
        $this->assertNull(ReecClient::parseSearch((string) file_get_contents(self::FIXTURES.'busqueda_empty.json'), '2026-525328-10-00'));

        $this->expectException(RegistryRequestFailed::class);
        ReecClient::parseListing('<html>maintenance</html>');
    }

    public function test_page_tokens_and_record_urls(): void
    {
        [$start, $offset] = ReecClient::decodeToken('2026-06-01|20');
        $this->assertSame('2026-06-01', $start->toDateString());
        $this->assertSame(20, $offset);

        $source = (new \ReflectionClass(ReecSource::class))->newInstanceWithoutConstructor();
        $this->assertSame('https://reec.aemps.es/reec/estudio/2026-525328-10-00', $source->recordUrl('2026-525328-10-00'));
        $this->assertSame('https://reec.aemps.es/reec/estudio/2015-002555-10', $source->recordUrl('2015-002555-10'));
        $this->assertNull($source->recordUrl('../admin'));
    }
}
