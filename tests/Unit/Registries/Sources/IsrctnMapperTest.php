<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Sources\Isrctn\IsrctnClient;
use App\Services\Registries\Sources\Isrctn\IsrctnMapper;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IsrctnMapperTest extends TestCase
{
    private const string URL = 'https://www.isrctn.com/api/trial/ISRCTN56688303/format/default';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/registries/isrctn/'.$name);
    }

    private static function listed(int $index): string
    {
        return IsrctnClient::parseListing(self::fixture('list.xml'))['trials'][$index];
    }

    private function map(string $xml): MappedStudy
    {
        return (new IsrctnMapper)->map(['xml' => $xml, 'url' => self::URL]);
    }

    public function test_a_recruiting_record_is_mapped(): void
    {
        $study = $this->map(self::listed(0));
        $a = $study->attributes;

        $this->assertSame('isrctn', $study->source);
        $this->assertSame('ISRCTN56688303', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertSame('Effect of an AI clinical decision support chatbot on care quality in rural Uganda', $a['brief_title']);
        $this->assertStringStartsWith('Effect of an AI clinical decision support chatbot on care quality in rural Ugandan', $a['official_title']);
        $this->assertNull($a['acronym']);
        $this->assertSame('RECRUITING', $a['overall_status']);
        $this->assertSame('2026-11-20', $a['start_date']);
        $this->assertSame('2027-12-31', $a['completion_date']);
        $this->assertSame('2026-11-23', $a['last_update_post_date']);
        $this->assertSame('2026-11-23', $a['study_first_post_date']);
        $this->assertSame('INTERVENTIONAL', $a['study_type']);
        $this->assertNull($a['phase']);
        // The final enrolment (0) is ignored while recruitment is open.
        $this->assertSame(65, $a['enrollment_count']);
        $this->assertSame('ESTIMATED', $a['enrollment_type']);
        $this->assertSame('18 Years', $a['min_age']);
        $this->assertSame('75 Years', $a['max_age']);
        $this->assertSame('ALL', $a['gender']);
        $this->assertSame('NO', $a['ipd_sharing']);
        $this->assertFalse($a['has_results']);
        $this->assertSame('London School of Economics and Political Science', $a['sponsor_name']);
        $this->assertNull($a['sponsor_class']);
        $this->assertStringStartsWith("Inclusion criteria:\nClinics must have", $a['eligibility_criteria']);
        $this->assertStringContainsString("Exclusion criteria:\nDoes not meet inclusion criteria", $a['eligibility_criteria']);

        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([['name' => 'OneDay Health', 'class' => null]], $study->relations['collaborators']);
        $this->assertSame('Quality of primary care for common acute conditions managed by nurses in rural primary care', $study->relations['conditions'][0]['condition']);
        $this->assertSame([['facility' => null, 'city' => null, 'state' => null, 'country' => 'Uganda', 'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null]], $study->relations['locations']);
        $this->assertSame('BEHAVIORAL', $study->relations['interventions'][0]['type']);
        $this->assertSame('Two-arm, parallel, cluster-randomised controlled trial; the clinic is the unit of randomisation.', $study->relations['interventions'][0]['name']);
        $this->assertSame([[
            'type' => 'PRIMARY',
            'measure' => 'Correct case management',
            'description' => 'unannounced standardised patient visits',
            'time_frame' => '6 months after introduction of the LLM-powered clinical decision support chatbot',
        ]], $study->relations['outcomes']);

        $this->assertSame('RECRUITING', $study->facts?->overallStatus);
        $this->assertSame('xml', $study->raw?->format);
        $this->assertSame(self::URL, $study->raw?->url);
        $this->assertStringContainsString('<isrctn dateAssigned=', (string) $study->raw?->content);
        // Contact persons are not stored.
        $this->assertStringNotContainsString('<contact ', (string) $study->raw?->content);
        $this->assertStringNotContainsString('forename', (string) $study->raw?->content);
        $this->assertStringNotContainsString('<email', (string) $study->raw?->content);
    }

    public function test_an_older_record_with_free_text_outcomes_is_mapped(): void
    {
        $study = $this->map(self::listed(1));
        $a = $study->attributes;

        $this->assertSame('ISRCTN49306926', $study->sourceId);
        $this->assertSame('COMPLETED', $a['overall_status']);
        $this->assertSame('2010-08-09', $a['start_date']);
        $this->assertSame('2010-08-12', $a['study_first_post_date']);
        $this->assertSame('NA', $a['phase']);
        $this->assertSame('OBSERVATIONAL', $a['study_type']);
        $this->assertSame('S52376', $a['org_study_id']);
        $this->assertSame('OTHER_GOV', $a['sponsor_class']);
        $this->assertNull($a['brief_summary'], '"Not provided at time of registration" is not a summary.');
        $this->assertNull($a['min_age']);
        // The sponsor's protocol serial number is never a linking identifier.
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame('23506003', $study->relations['references'][0]['pmid']);
        $this->assertSame('http://www.ncbi.nlm.nih.gov/pubmed/23506003', $study->relations['references'][0]['url']);
        $this->assertSame('Director of the Department of Intensive Care Medicine', $study->relations['locations'][0]['facility']);
        $this->assertSame('Leuven', $study->relations['locations'][0]['city']);
        $this->assertCount(1, $study->relations['locations']);
        $this->assertCount(1, $study->relations['outcomes'], 'A secondary outcome of "None" is skipped.');
        $this->assertSame('PRIMARY', $study->relations['outcomes'][0]['type']);
    }

    public function test_registry_numbers_phases_overrides_and_final_enrolment(): void
    {
        $study = $this->map(self::fixture('record_full.xml'));
        $a = $study->attributes;

        $this->assertSame('ISRCTN00000001', $study->sourceId);
        $this->assertSame('TERMINATED', $a['overall_status'], 'The "Stopped" override wins over the dates.');
        $this->assertSame('PHASE2, PHASE3', $a['phase']);
        $this->assertSame('DRASTHMA', $a['acronym']);
        $this->assertSame('2024-01-01', $a['start_date']);
        $this->assertSame('2026-06-30', $a['completion_date']);
        $this->assertSame(212, $a['enrollment_count']);
        $this->assertSame('ACTUAL', $a['enrollment_type']);
        $this->assertSame('18 Years', $a['min_age']);
        $this->assertSame('65 Years', $a['max_age']);
        $this->assertSame('FEMALE', $a['gender']);
        $this->assertSame('YES', $a['ipd_sharing']);
        $this->assertTrue($a['has_results']);
        $this->assertSame('Example Pharma Ltd', $a['sponsor_name']);
        $this->assertSame('INDUSTRY', $a['sponsor_class']);

        $ids = array_column($study->relations['secondaryIds'], 'type', 'secondary_id');
        $this->assertSame([
            'NCT01234567' => 'NCT',
            '2023-000123-45' => 'EudraCT',
            '312345' => 'IRAS',
            '2023-501234-12-00' => 'CTIS',
        ], $ids);

        $this->assertSame(['Example University', 'Example Research Council'], array_column($study->relations['collaborators'], 'name'));
        $this->assertCount(1, $study->relations['conditions']);
        $this->assertSame(['Royal Example Hospital', null], array_column($study->relations['locations'], 'facility'));
        $this->assertSame(['England', 'Germany'], array_column($study->relations['locations'], 'country'));
        $this->assertSame([['type' => 'DRUG', 'name' => 'Drug A', 'description' => 'Drug A 10 mg once daily or matching placebo for 52 weeks.']], $study->relations['interventions']);
        $this->assertSame(['PRIMARY', 'SECONDARY'], array_column($study->relations['outcomes'], 'type'));
        $this->assertTrue($study->facts?->hasResults);
    }

    public function test_missing_optional_fields_become_null_or_empty(): void
    {
        $study = $this->map(self::fixture('record_minimal.xml'));
        $full = $this->map(self::fixture('record_full.xml'));
        $a = $study->attributes;

        $this->assertSame(array_keys($full->attributes), array_keys($a), 'Every record fills the same columns.');
        $this->assertSame('ISRCTN00000002', $study->sourceId);
        $this->assertSame('Minimal record', $a['brief_title']);

        foreach (['official_title', 'acronym', 'org_study_id', 'overall_status', 'start_date', 'completion_date', 'study_first_post_date',
            'brief_summary', 'detailed_description', 'study_type', 'phase', 'enrollment_count', 'enrollment_type', 'sponsor_name',
            'sponsor_class', 'eligibility_criteria', 'min_age', 'max_age', 'gender', 'ipd_sharing'] as $column) {
            $this->assertNull($a[$column], $column);
        }

        $this->assertFalse($a['has_results']);

        foreach ($study->relations as $name => $rows) {
            $this->assertSame([], $rows, $name);
        }
    }

    public function test_the_status_follows_the_dates(): void
    {
        CarbonImmutable::setTestNow('2026-11-01');
        $this->assertSame('NOT_YET_RECRUITING', $this->map(self::listed(0))->attributes['overall_status']);

        CarbonImmutable::setTestNow('2027-03-01');
        $study = $this->map(self::listed(0));
        $this->assertSame('ACTIVE_NOT_RECRUITING', $study->attributes['overall_status']);
        $this->assertSame(0, $study->attributes['enrollment_count'], 'The final enrolment, even 0, once recruitment has ended.');
        $this->assertSame('ACTUAL', $study->attributes['enrollment_type']);

        CarbonImmutable::setTestNow('2028-01-01');
        $this->assertSame('COMPLETED', $this->map(self::listed(0))->attributes['overall_status']);
    }

    public function test_email_addresses_and_phone_numbers_are_removed_from_text_and_raw_record(): void
    {
        $xml = str_replace('Redacted, Redacted</plainEnglishSummary>', 'Dr A, a.person@example.org, +44 7595 911011</plainEnglishSummary>', self::listed(0));

        $study = $this->map($xml);

        $this->assertStringEndsWith('Dr A, [email removed], [phone removed]', (string) $study->attributes['brief_summary']);
        $this->assertStringNotContainsString('example.org', (string) $study->raw?->content);
        $this->assertStringNotContainsString('7595', (string) $study->raw?->content);
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        $mapper = new IsrctnMapper;

        foreach ([[], ['xml' => 'not xml'], ['xml' => '<other xmlns="http://www.67bricks.com/isrctn"/>'],
            ['xml' => '<fullTrial xmlns="http://www.67bricks.com/isrctn"><trial><trialDescription><title>T</title></trialDescription></trial></fullTrial>']] as $payload) {
            try {
                $mapper->map($payload);
                $this->fail('Expected InvalidStudyPayload.');
            } catch (InvalidStudyPayload) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($mapper->payloadId([]));
        $this->assertSame('ISRCTN49306926', $mapper->payloadId(['xml' => self::listed(1)]));
    }

    public function test_the_listing_is_split_into_records(): void
    {
        $listing = IsrctnClient::parseListing(self::fixture('list.xml'));

        $this->assertSame(2, $listing['total']);
        $this->assertCount(2, $listing['trials']);
        $this->assertStringStartsWith('<fullTrial xmlns="http://www.67bricks.com/isrctn"', $listing['trials'][1]);
        $this->assertSame(['total' => 0, 'trials' => []], IsrctnClient::parseListing(self::fixture('empty.xml')));

        foreach (['', '<html><body>Maintenance</body></html>', '<allTrials xmlns="http://www.67bricks.com/isrctn"/>', '<allTrials totalCount="3">'] as $body) {
            try {
                IsrctnClient::parseListing($body);
                $this->fail('Expected RegistryRequestFailed.');
            } catch (RegistryRequestFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_page_tokens_and_window_sizing(): void
    {
        $token = IsrctnClient::encodeToken(CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'), 3600);
        $this->assertSame('2026-11-20T06:30:00|3600', $token);
        [$start, $window] = IsrctnClient::decodeToken($token);
        $this->assertSame('2026-11-20 06:30:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame(3600, $window);

        // An empty window grows four times, a full one keeps about 80% of a response.
        $this->assertSame(400, IsrctnClient::nextWindow(100, 0, 100, 86_400));
        $this->assertSame(80, IsrctnClient::nextWindow(100, 100, 100, 86_400));
        $this->assertSame(86_400, IsrctnClient::nextWindow(86_400, 0, 100, 86_400));

        $this->expectException(InvalidArgumentException::class);
        IsrctnClient::decodeToken('yesterday');
    }
}
