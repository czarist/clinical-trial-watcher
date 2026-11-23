<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Anzctr;

use App\Enums\AccessMethod;
use App\Enums\IdentifierScheme;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * The Australian New Zealand Clinical Trials Registry (anzctr.org.au), read from its trial
 * pages. Records are identified by their ACTRN number.
 *
 * Dormant until ANZCTR gives express approval: its terms (4.1) forbid collecting information
 * with software it has not approved, so requests are refused unless
 * `registries.permissions.anzctr` is enabled. Reuse is otherwise allowed under clause 5.5 with
 * attribution, current data, the processing date and a description of modifications.
 */
final readonly class AnzctrSource implements RegistrySource
{
    public const string KEY = 'anzctr';

    public const string BASE_URL = 'https://www.anzctr.org.au';

    public const string TRIAL_PATH = '/Trial/Registration/TrialReview.aspx';

    public function __construct(
        private RegistryFetcher $fetcher,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'ANZCTR';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: self::BASE_URL.'/Support/Terms.aspx',
            licence: 'Copyright © ANZCTR, all rights reserved. Clause 5.5 allows royalty-free reuse with attribution to ANZCTR, current data, the processing date and a description of modifications, but clause 4.1 forbids automated collection not expressly approved by ANZCTR: written permission required. No robots.txt is published.',
            attribution: 'Source: Australian New Zealand Clinical Trials Registry (ANZCTR), processed on the date shown; fields reformatted and summarised.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: (bool) config('registries.permissions.'.self::KEY, false),
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return new AnzctrClient($this->fetcher, $this);
    }

    public function mapper(): StudyPayloadMapper
    {
        return new AnzctrMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        $id = self::canonicalId($sourceId);

        return $id !== null && $id === $sourceId ? self::trialUrl($id) : null;
    }

    /** Trial page of a canonical ACTRN number. */
    public static function trialUrl(string $id): string
    {
        return self::BASE_URL.self::TRIAL_PATH.'?'.http_build_query(['ACTRN' => substr($id, 5)]);
    }

    /** The ACTRN number written one way (ACTRN12622000561785), or null when the value is not one. */
    public static function canonicalId(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match(IdentifierScheme::Actrn->pattern(), $value, $matches) !== 1) {
            return null;
        }

        return IdentifierScheme::Actrn->format(array_values(array_slice($matches, 1)));
    }
}
