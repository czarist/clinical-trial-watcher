<?php

declare(strict_types=1);

namespace Tests\Unit\Registries\Sources;

use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Sources\MozUkraine\MozUkraineClient;
use App\Services\Registries\Sources\MozUkraine\MozUkraineMapper;
use PHPUnit\Framework\TestCase;

/**
 * annex_1807.txt is the output of `pdftotext -layout -enc UTF-8` (poppler 25.12) for the annex
 * of MOZ order № 1807 of 13.11.2026, with investigators' and signatories' names redacted.
 */
final class MozUkraineMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/moz_ukraine/';

    private const string ANNEX_URL = 'https://moz.gov.ua/storage/uploads/64071e3b-f46a-4891-a1f0-d1c00c1ec439/dn_1807_13112026_dod.pdf';

    /**
     * @return list<array<string, mixed>>
     */
    private static function annexes(): array
    {
        return MozUkraineClient::annexes((string) file_get_contents(self::FIXTURES.'annex_1807.txt'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(int $index): array
    {
        return [...self::annexes()[$index], 'url' => self::ANNEX_URL, 'order_url' => 'https://moz.gov.ua/uk/decrees/x'];
    }

    public function test_clinical_trial_orders_are_picked_from_the_listing(): void
    {
        $orders = MozUkraineClient::orders((string) file_get_contents(self::FIXTURES.'listing_page1.html'), MozUkraineClient::LISTING_URL);

        $this->assertCount(10, $orders);
        $trialOrders = array_values(array_filter($orders, static fn (array $order): bool => $order['clinical_trials']));
        $this->assertCount(1, $trialOrders);
        $this->assertSame('1838', $trialOrders[0]['number']);
        $this->assertSame('2026-11-18', $trialOrders[0]['date']);
        $this->assertStringStartsWith('https://moz.gov.ua/uk/decrees/nakaz-moz-ukrayini-vid-18-11-2026-1838-pro-provedennya-klinichnogo', $trialOrders[0]['url']);
    }

    public function test_an_empty_listing_has_no_orders_and_a_changed_one_is_refused(): void
    {
        $this->assertSame([], MozUkraineClient::orders((string) file_get_contents(self::FIXTURES.'listing_empty.html'), MozUkraineClient::LISTING_URL));

        $this->expectException(RegistryRequestFailed::class);
        MozUkraineClient::orders('<html><body><p>Сторінку не знайдено</p></body></html>', MozUkraineClient::LISTING_URL);
    }

    public function test_the_annex_pdf_is_found_on_the_order_page(): void
    {
        $this->assertSame([self::ANNEX_URL], MozUkraineClient::annexUrls((string) file_get_contents(self::FIXTURES.'decree_1807.html'), 'https://moz.gov.ua/uk/decrees/x'));
    }

    public function test_annexes_are_split_into_labelled_fields(): void
    {
        $annexes = self::annexes();

        $this->assertCount(14, $annexes);
        $this->assertSame(['1', '1807', '2026-11-13'], [$annexes[0]['annex'], $annexes[0]['order_number'], $annexes[0]['order_date']]);
        $this->assertSame(['title', 'applicant', 'sponsor', 'imps', 'sites', 'comparators', 'auxiliary'], array_keys($annexes[0]['fields']));
        $this->assertArrayHasKey('amendment', $annexes[2]['fields']);
        $this->assertSame(['№ 299 від 09.03.2026'], $annexes[2]['fields']['authorisation_order']);
        // Columns shift between pages and lines: values keep their first letter.
        $this->assertStringStartsWith('Компанія, яка діє', $annexes[0]['fields']['auxiliary'][0]);
        $this->assertStringStartsWith('супутніх матеріалів', $annexes[0]['fields']['auxiliary'][2]);
    }

    public function test_an_authorised_trial_is_mapped(): void
    {
        $study = (new MozUkraineMapper)->map(self::payload(0));

        $this->assertSame('moz_ukraine', $study->source);
        $this->assertSame('D8890C00001', $study->sourceId);
        $this->assertNull($study->nctId);
        $this->assertStringStartsWith('Проспективне, рандомізоване, відкрите дослідження III фази', $study->attributes['brief_title']);
        $this->assertStringEndsWith('із гострим інфарктом міокарда', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['overall_status']);
        $this->assertSame('PHASE3', $study->attributes['phase']);
        $this->assertSame('2026-11-13', $study->attributes['study_first_post_date']);
        $this->assertSame('AstraZeneca AB, Sweden', $study->attributes['sponsor_name']);
        $this->assertSame([['name' => 'ТОВ «АСТРАЗЕНЕКА УКРАЇНА»', 'class' => null]], $study->relations['collaborators']);

        $this->assertCount(4, $study->relations['locations']);
        $this->assertSame('Ужгород', $study->relations['locations'][0]['city']);
        $this->assertSame('Ukraine', $study->relations['locations'][0]['country']);
        $this->assertStringStartsWith('Комунальне некомерційне підприємство «Закарпатський', $study->relations['locations'][0]['facility']);
        $this->assertSame('Івано-Франківськ', $study->relations['locations'][3]['city']);
        $this->assertStringContainsString('Івано-Франківської міської ради', $study->relations['locations'][3]['facility']);

        $this->assertCount(1, $study->relations['interventions']);
        $this->assertSame('DRUG', $study->relations['interventions'][0]['type']);
        $this->assertStringStartsWith('Ларопровстат (Laroprovstat)/ Розувастатин (Rosuvastatin)', $study->relations['interventions'][0]['name']);
        $this->assertSame([], $study->relations['secondaryIds']);
        $this->assertSame([], $study->relations['conditions']);

        $this->assertNull($study->facts?->overallStatus);
        $this->assertSame('pdf', $study->raw?->format);
        $this->assertSame(self::ANNEX_URL, $study->raw->url);
        $this->assertStringContainsString('order: 1807 of 2026-11-13', $study->raw->content);
        $this->assertStringNotContainsString('Redacted', $study->raw->content);
    }

    public function test_investigators_names_are_dropped_from_sites(): void
    {
        $payload = self::payload(1);
        $payload['fields']['sites'] = ['1) к.м.н. Іваненко І.І.', 'Клінічна лікарня №1, терапевтичне відділення, м. Київ', '2) д.м.н. Петренко П.П. Обласна лікарня, м. Львів'];

        $locations = (new MozUkraineMapper)->map($payload)->relations['locations'];

        $this->assertSame(['Клінічна лікарня №1, терапевтичне відділення', 'Обласна лікарня'], array_column($locations, 'facility'));
        $this->assertSame(['Київ', 'Львів'], array_column($locations, 'city'));
        $this->assertStringNotContainsString('Іваненко', json_encode($locations, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_codes_and_phases_of_other_title_formats(): void
    {
        $mapper = new MozUkraineMapper;

        $this->assertSame('230LE305', $mapper->payloadId(self::payload(1)));
        // Amendment annexes write the code without "код дослідження".
        $this->assertSame('MB-001-102', $mapper->payloadId(self::payload(2)));
        $this->assertSame('PHASE1, PHASE2', $mapper->map(self::payload(2))->attributes['phase']);
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $study = (new MozUkraineMapper)->map(['fields' => ['title' => ['«Дослідження», код дослідження ABC-1, версія 1 від 1 січня 2026 року']]]);

        $this->assertSame('ABC-1', $study->sourceId);
        $this->assertSame('Дослідження', $study->attributes['brief_title']);
        $this->assertNull($study->attributes['phase']);
        $this->assertNull($study->attributes['sponsor_name']);
        $this->assertNull($study->attributes['study_first_post_date']);
        $this->assertSame([], $study->relations['locations']);
        $this->assertSame([], $study->relations['interventions']);
        $this->assertSame([], $study->relations['collaborators']);
        $this->assertNull($study->raw?->url);
    }

    public function test_every_record_has_the_same_attribute_keys(): void
    {
        $mapper = new MozUkraineMapper;

        $this->assertSame(array_keys($mapper->map(self::payload(0))->attributes), array_keys($mapper->map(self::payload(1))->attributes));
    }

    public function test_an_annex_without_protocol_code_is_rejected(): void
    {
        $payload = ['fields' => ['title' => ['«Дослідження без коду»']]];

        $this->assertNull((new MozUkraineMapper)->payloadId($payload));
        $this->expectException(InvalidStudyPayload::class);
        (new MozUkraineMapper)->map($payload);
    }
}
