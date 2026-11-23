<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Rebec;

use App\Enums\AccessMethod;
use App\Enums\IdentifierScheme;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * ReBEC, the Brazilian Clinical Trials Registry (ensaiosclinicos.gov.br), read from its public
 * list and trial pages. Records are identified by their RBR number. The per-trial ICTRP XML
 * export (/xml_ictrp/) is disallowed by robots.txt and is never requested.
 */
final readonly class RebecSource implements RegistrySource
{
    public const string KEY = 'rebec';

    public const string BASE_URL = 'https://ensaiosclinicos.gov.br';

    public function __construct(
        private RegistryFetcher $fetcher,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'ReBEC';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: self::BASE_URL.'/page/about',
            licence: 'No reuse licence or terms published ("Todos os direitos reservados"); public registry of the Brazilian Ministry of Health and Fiocruz. robots.txt allows /list/ and /rg/ and disallows /xml_ictrp/.',
            attribution: 'Source: ReBEC – Registro Brasileiro de Ensaios Clínicos (ensaiosclinicos.gov.br)',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return new RebecClient($this->fetcher, $this);
    }

    public function mapper(): StudyPayloadMapper
    {
        return new RebecMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        $id = self::canonicalId($sourceId);

        return $id !== null && $id === $sourceId ? self::BASE_URL.'/rg/'.$id : null;
    }

    /** The RBR number written one way (RBR-xxxxxxx), or null when the value is not one. */
    public static function canonicalId(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match(IdentifierScheme::Rbr->pattern(), $value, $matches) !== 1) {
            return null;
        }

        return IdentifierScheme::Rbr->format(array_values(array_slice($matches, 1)));
    }
}
