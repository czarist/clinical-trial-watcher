<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\Orphanet\OrphanetClient;
use App\Services\Registries\Sources\Orphanet\OrphanetMapper;
use App\Services\Registries\Sources\Orphanet\OrphanetSource;
use PHPUnit\Framework\TestCase;

final class OrphanetMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/orphanet/';

    /**
     * The trial payloads of the synthetic dump, keyed by trial id.
     *
     * @return array<string, array{id: string|null, xml: string}>
     */
    private function trials(string $name = 'synthetic_trials.xml'): array
    {
        $trials = [];

        foreach (OrphanetClient::trials(self::FIXTURES.$name) as $trial) {
            $trials[(string) $trial['id']] = $trial;
        }

        return $trials;
    }

    private function temporaryFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'orphanet-test');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_a_trial_is_mapped(): void
    {
        $study = (new OrphanetMapper)->map($this->trials()['900001']);

        $this->assertSame(OrphanetSource::KEY, $study->source);
        $this->assertSame('900001', $study->sourceId);
        $this->assertSame('SYNTH-1: Phase III Study Comparing Product A With Standard Care in Synthetic Vasculitis - FR', $study->attributes['brief_title']);
        $this->assertStringStartsWith('SYNTH-1 : Étude de phase III', $study->attributes['official_title']);
        $this->assertSame('RECRUITING', $study->attributes['overall_status']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('INTERVENTIONAL', $study->attributes['study_type']);
        $this->assertSame('2025-03-15', $study->attributes['start_date']);
        $this->assertSame('2028-06-30', $study->attributes['completion_date']);
        $this->assertSame('2026-02-10', $study->attributes['last_update_post_date']);
        $this->assertSame('Synthetic Hospitals Trust (DRC)', $study->attributes['sponsor_name']);
        // The detail only repeats identifiers.
        $this->assertNull($study->attributes['detailed_description']);

        $this->assertSame([['condition' => 'Synthetic vasculitis'], ['condition' => 'Synthetic granulomatosis']], $study->relations['conditions']);
        $this->assertSame([['keyword' => 'ORPHA:99001'], ['keyword' => 'ORPHA:99002']], $study->relations['keywords']);
        $this->assertSame([
            ['type' => 'DRUG', 'name' => 'Synthamab', 'description' => null],
            ['type' => 'DRUG', 'name' => 'Synthavex', 'description' => 'Trade name'],
        ], $study->relations['interventions']);

        // Investigator sites, not the sponsor's address.
        $this->assertCount(2, $study->relations['locations']);
        $this->assertSame('Synthetic Central Hospital', $study->relations['locations'][0]['facility']);
        $this->assertSame('Saint-Étienne', $study->relations['locations'][0]['city']);
        $this->assertSame('France', $study->relations['locations'][0]['country']);
        $this->assertSame('Lyon', $study->relations['locations'][1]['city']);

        // NCT and EU CT numbers from the links and the EudraCT number from the detail link the record.
        $this->assertSame(['NCT09000001', '2024-590001-11-00', '2019-000123-45'], array_column($study->relations['secondaryIds'], 'secondary_id'));
        $keys = array_map(fn ($identifier): string => $identifier->key(), (new IdentifierParser)->fromStudy($study));
        $this->assertSame(['nct:NCT09000001', 'eu_ct:2024-590001-11-00', 'eudract:2019-000123-45'], $keys);

        // The individual named as investigator is not kept in the raw record.
        $this->assertSame('xml', $study->raw?->format);
        $this->assertSame('http://www.orpha.net/consor/cgi-bin/OC_Exp.php?lng=en&Expert=990001', $study->raw?->url);
        $this->assertStringStartsWith('<ClinicalTrial id="900001">', (string) $study->raw?->content);
        $this->assertStringNotContainsString('Redacted', (string) $study->raw?->content);
        $this->assertStringContainsString('Synthetic Hospitals Trust (DRC)', (string) $study->raw?->content);
    }

    public function test_missing_fields_map_to_null(): void
    {
        $study = (new OrphanetMapper)->map($this->trials()['900002']);

        $this->assertSame('900002', $study->sourceId);
        $this->assertSame('Synthetic observational trial with few fields', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['official_title']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertNull($study->attributes['phase']);
        $this->assertNull($study->attributes['study_type']);
        $this->assertNull($study->attributes['start_date']);
        $this->assertNull($study->attributes['last_update_post_date']);
        $this->assertNull($study->attributes['sponsor_name']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['conditions']);
        $this->assertSame([], $study->relations['locations']);
        $this->assertNull($study->raw?->url);
    }

    public function test_combined_phases_individual_sponsors_and_other_registries(): void
    {
        $study = (new OrphanetMapper)->map($this->trials()['900003']);

        $this->assertSame('PHASE1, PHASE2', $study->attributes['phase']);
        $this->assertNull($study->attributes['official_title']);
        $this->assertNull($study->attributes['overall_status']);
        // A sponsor entry naming a person falls back to the institution.
        $this->assertSame('Synthetic Gene Therapy Unit', $study->attributes['sponsor_name']);
        $this->assertSame('Sponsor protocol SYN-GT-01; see DRKS00090003', $study->attributes['detailed_description']);
        // The sponsor protocol number is never used as an identifier.
        $this->assertSame(['ISRCTN90000003', 'DRKS00090003'], array_column($study->relations['secondaryIds'], 'secondary_id'));
        $this->assertSame([], $study->relations['locations']);
        $this->assertStringNotContainsString('Redacted', (string) $study->raw?->content);
    }

    public function test_every_record_has_the_same_attribute_keys(): void
    {
        $mapper = new OrphanetMapper;
        $trials = $this->trials();

        $this->assertSame(array_keys($mapper->map($trials['900001'])->attributes), array_keys($mapper->map($trials['900002'])->attributes));
    }

    public function test_identifiers_are_parsed_not_guessed(): void
    {
        $rows = (new OrphanetMapper)->identifiersIn('https://euclinicaltrials.eu/?EUCT=2024-513653-75-00', 'protocol 2024-12345, NCT123');

        $this->assertSame(['2024-513653-75-00'], array_column($rows, 'secondary_id'));
        $this->assertSame('EU CT number', $rows[0]['type']);
    }

    public function test_a_trial_without_an_id_is_rejected(): void
    {
        $this->assertNull((new OrphanetMapper)->payloadId(['id' => null, 'xml' => '<ClinicalTrial><Name>x</Name></ClinicalTrial>']));

        $this->expectException(InvalidStudyPayload::class);
        (new OrphanetMapper)->map(['id' => null, 'xml' => '<ClinicalTrial><Name>x</Name></ClinicalTrial>']);
    }

    public function test_the_dump_is_streamed_from_an_offset(): void
    {
        $this->assertSame(['900001', '900002', '900003'], array_column($this->trials(), 'id'));

        $rest = iterator_to_array(OrphanetClient::trials(self::FIXTURES.'synthetic_trials.xml', 2));
        $this->assertSame([2], array_keys($rest));
        $this->assertSame('900003', $rest[2]['id']);

        $this->assertSame([], $this->trials('synthetic_empty.xml'));
    }

    public function test_a_file_that_is_not_a_clinical_trials_dump_is_rejected(): void
    {
        $path = $this->temporaryFile('<?xml version="1.0"?><JDBOR><DisorderList count="0"/></JDBOR>');

        try {
            $this->expectException(RegistryRequestFailed::class);
            iterator_to_array(OrphanetClient::trials($path));
        } finally {
            unlink($path);
        }
    }

    public function test_an_html_page_is_rejected(): void
    {
        $path = $this->temporaryFile('<html><body><form action="/_challenge">Vérification</form></body></html>');

        try {
            $this->expectException(RegistryRequestFailed::class);
            iterator_to_array(OrphanetClient::trials($path));
        } finally {
            unlink($path);
        }
    }

    public function test_a_truncated_dump_is_rejected(): void
    {
        $content = (string) file_get_contents(self::FIXTURES.'synthetic_trials.xml');
        $path = $this->temporaryFile(substr($content, 0, (int) strpos($content, '<ClinicalTrial id="900003">') + 60));

        try {
            $this->expectException(RegistryRequestFailed::class);
            iterator_to_array(OrphanetClient::trials($path));
        } finally {
            unlink($path);
        }
    }

    public function test_no_record_url_is_built_from_a_trial_id(): void
    {
        $source = (new \ReflectionClass(OrphanetSource::class))->newInstanceWithoutConstructor();

        $this->assertNull($source->recordUrl('900001'));
    }
}
