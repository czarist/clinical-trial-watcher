<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The languages the application is offered in. English is the default and the fallback.
 *
 * The value is Laravel's locale code (the name of the folder in lang/); tag() is the BCP 47
 * tag used by browsers, the web app and the Accept-Language header.
 */
enum Locale: string
{
    case English = 'en';
    case BrazilianPortuguese = 'pt_BR';
    case Spanish = 'es';
    case SimplifiedChinese = 'zh_CN';
    case TraditionalChinese = 'zh_TW';
    case Japanese = 'ja';
    case Korean = 'ko';

    public static function default(): self
    {
        return self::English;
    }

    /**
     * Maps a language code such as "pt-BR", "pt", "zh-Hant-HK" or "zh_CN" to a supported
     * locale, or null when the language is not offered.
     */
    public static function fromCode(string $code): ?self
    {
        $parts = array_map(strtolower(...), preg_split('/[-_]/', trim($code)) ?: []);
        $language = $parts[0] ?? '';

        if ($language === 'zh') {
            $traditional = array_intersect($parts, ['hant', 'tw', 'hk', 'mo']) !== [];

            return $traditional ? self::TraditionalChinese : self::SimplifiedChinese;
        }

        return match ($language) {
            'en' => self::English,
            'pt' => self::BrazilianPortuguese,
            'es' => self::Spanish,
            'ja' => self::Japanese,
            'ko' => self::Korean,
            default => null,
        };
    }

    /**
     * The first supported language in preference order, or English.
     *
     * @param  iterable<string>  $codes
     */
    public static function negotiate(iterable $codes): self
    {
        foreach ($codes as $code) {
            $locale = self::fromCode($code);

            if ($locale !== null) {
                return $locale;
            }
        }

        return self::default();
    }

    public function tag(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /**
     * Name given to the model when it writes in this language.
     */
    public function promptName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::BrazilianPortuguese => 'Brazilian Portuguese',
            self::Spanish => 'Spanish',
            self::SimplifiedChinese => 'Simplified Chinese',
            self::TraditionalChinese => 'Traditional Chinese',
            self::Japanese => 'Japanese',
            self::Korean => 'Korean',
        };
    }
}
