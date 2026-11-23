<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Reec;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Registro Español de Estudios Clínicos (REec, AEMPS), read through its documented REST
 * service. REec identifies a clinical trial by the identifier it was authorised under: the EU
 * CT number for trials under Regulation 536/2014, the EudraCT number for older ones. That
 * identifier is the record's source id, so a REec record links to the CTIS or EU CTR record
 * of the same trial.
 */
final class ReecSource implements RegistrySource
{
    public const string KEY = 'reec';

    private ?ReecClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'REec';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://www.aemps.gob.es/aviso-legal/',
            licence: 'AEMPS legal notice: total or partial reproduction allowed provided the origin is cited and the date of the last update of the reused documents is mentioned; no commercial restriction stated. The REec query service is offered to any entity that wants to extract data.',
            attribution: 'Fuente: Registro Español de Estudios Clínicos (REec), AEMPS (reec.aemps.es); última actualización: fecha de la importación.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: null,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new ReecClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.reec.base_url', 'https://reec.aemps.es/reec-services'),
            (string) config('registries.options.reec.full_import_from', '2013-01-01'),
            (int) config('registries.options.reec.window_days', 14),
            (int) config('registries.options.reec.lookback_days', 90),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new ReecMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        return ReecMapper::isTrialNumber($sourceId) ? 'https://reec.aemps.es/reec/estudio/'.$sourceId : null;
    }
}
