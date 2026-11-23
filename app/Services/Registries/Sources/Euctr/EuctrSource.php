<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Euctr;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * EU Clinical Trials Register (clinicaltrialsregister.eu), the EudraCT protocols of trials
 * under the former Clinical Trials Directive, read through the register's plain-text
 * "full trial details" download of each search results page. Records are identified by
 * their EudraCT number (YYYY-NNNNNN-CC); the register's one protocol per member state is
 * merged into one record.
 */
final class EuctrSource implements RegistrySource
{
    public const string KEY = 'euctr';

    public const string ID_PATTERN = '/^(?:19|20)\d{2}-\d{6}-\d{2}$/';

    private ?EuctrClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'EU Clinical Trials Register';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Download,
            termsUrl: 'https://www.clinicaltrialsregister.eu/disclaimer.html',
            licence: 'Reuse allowed with attribution to the "EU Clinical Trials Register" and the date the data were accessed; extracted e-mail addresses must not be used for marketing. Copyright follows the EMA legal notice, which allows commercial reproduction with acknowledgement of EMA (third-party content excluded). No robots.txt (404); automated access is not addressed.',
            attribution: 'Source: EU Clinical Trials Register (www.clinicaltrialsregister.eu), accessed on the date of the last import.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: 3000,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new EuctrClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.euctr.base_url', 'https://www.clinicaltrialsregister.eu/ctr-search'),
            (string) config('registries.options.euctr.query', ''),
            (int) config('registries.options.euctr.trials_per_page', 20),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new EuctrMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        // A record page needs a member state; the search by EudraCT number lists every one.
        return preg_match(self::ID_PATTERN, $sourceId) === 1
            ? 'https://www.clinicaltrialsregister.eu/ctr-search/search?query='.$sourceId
            : null;
    }
}
