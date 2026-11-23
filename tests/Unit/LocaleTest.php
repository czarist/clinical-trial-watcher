<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?Locale}>
     */
    public static function codes(): iterable
    {
        yield 'English' => ['en-US', Locale::English];
        yield 'Portuguese from Portugal maps to the offered Portuguese' => ['pt-PT', Locale::BrazilianPortuguese];
        yield 'Brazilian Portuguese, Laravel style' => ['pt_BR', Locale::BrazilianPortuguese];
        yield 'Spanish from Mexico' => ['es-MX', Locale::Spanish];
        yield 'plain Chinese' => ['zh', Locale::SimplifiedChinese];
        yield 'Chinese, Simplified script' => ['zh-Hans-CN', Locale::SimplifiedChinese];
        yield 'Chinese, Taiwan' => ['zh-TW', Locale::TraditionalChinese];
        yield 'Chinese, Hong Kong' => ['zh-HK', Locale::TraditionalChinese];
        yield 'Chinese, Traditional script (Symfony normalized)' => ['zh_HANT_MO', Locale::TraditionalChinese];
        yield 'Japanese' => ['ja-JP', Locale::Japanese];
        yield 'Korean' => ['ko', Locale::Korean];
        yield 'not offered' => ['fr-FR', null];
    }

    #[DataProvider('codes')]
    public function test_language_codes_map_to_supported_locales(string $code, ?Locale $expected): void
    {
        $this->assertSame($expected, Locale::fromCode($code));
    }

    public function test_negotiation_takes_the_first_supported_language_or_english(): void
    {
        $this->assertSame(Locale::Japanese, Locale::negotiate(['fr_FR', 'de', 'ja', 'en']));
        $this->assertSame(Locale::English, Locale::negotiate(['fr_FR', 'de']));
        $this->assertSame(Locale::English, Locale::negotiate([]));
    }

    public function test_tags_are_bcp47(): void
    {
        $this->assertSame('zh-TW', Locale::TraditionalChinese->tag());
        $this->assertSame('pt-BR', Locale::BrazilianPortuguese->tag());
        $this->assertSame('en', Locale::English->tag());
    }
}
