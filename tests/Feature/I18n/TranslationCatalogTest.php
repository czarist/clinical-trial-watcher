<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use App\Enums\Locale;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every offered language has every message the application uses, with the same placeholders,
 * so no reader gets an English sentence or a broken ":name" in the middle of their language.
 */
final class TranslationCatalogTest extends TestCase
{
    /** Application catalogs written in English first. */
    private const array APP_FILES = ['errors', 'messages', 'mail', 'digest'];

    /** Framework catalogs each language must provide in full. */
    private const array FRAMEWORK_FILES = ['auth', 'passwords', 'validation'];

    /**
     * @return iterable<string, array{Locale}>
     */
    public static function translatedLocales(): iterable
    {
        foreach (Locale::cases() as $locale) {
            if ($locale !== Locale::English) {
                yield $locale->value => [$locale];
            }
        }
    }

    #[DataProvider('translatedLocales')]
    public function test_application_catalogs_have_every_key_and_placeholder(Locale $locale): void
    {
        foreach (self::APP_FILES as $file) {
            $english = Arr::dot(require lang_path("en/{$file}.php"));
            $translated = Arr::dot(require lang_path("{$locale->value}/{$file}.php"));

            $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))), "{$locale->value}/{$file}.php is missing keys");
            $this->assertSame([], array_values(array_diff(array_keys($translated), array_keys($english))), "{$locale->value}/{$file}.php has unknown keys");

            foreach ($english as $key => $text) {
                $this->assertSame($this->placeholders((string) $text), $this->placeholders((string) $translated[$key]), "{$locale->value}/{$file}.php: placeholders of {$key}");
            }
        }
    }

    #[DataProvider('translatedLocales')]
    public function test_framework_catalogs_and_mail_strings_are_translated(Locale $locale): void
    {
        $framework = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        foreach (self::FRAMEWORK_FILES as $file) {
            // The framework's `custom` example and empty `attributes` are placeholders, not messages.
            $english = array_filter(
                Arr::dot(require "{$framework}/{$file}.php"),
                fn (string $key): bool => ! str_starts_with($key, 'custom.') && $key !== 'attributes',
                ARRAY_FILTER_USE_KEY,
            );
            $translated = Arr::dot(require lang_path("{$locale->value}/{$file}.php"));

            $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))), "{$locale->value}/{$file}.php is missing keys");
        }

        $this->assertNotSame('validation.attributes.email', trans('validation.attributes.email', [], $locale->value));

        $json = json_decode((string) file_get_contents(lang_path("{$locale->value}.json")), true, flags: JSON_THROW_ON_ERROR);

        foreach (['Hello!', 'Regards,', 'Verify Email Address', 'Reset Password', 'The given data was invalid.'] as $key) {
            $this->assertArrayHasKey($key, $json, "{$locale->value}.json");
        }
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
