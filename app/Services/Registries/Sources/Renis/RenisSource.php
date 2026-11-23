<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Renis;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Registro Nacional de Investigaciones en Salud (RENIS, Argentina), read from the cumulative
 * XLSX the Ministry of Health publishes on datos.salud.gob.ar about twice a year.
 */
final readonly class RenisSource implements RegistrySource
{
    public const string KEY = 'renis';

    private RenisClient $client;

    private RenisMapper $mapper;

    public function __construct(RegistryFetcher $fetcher)
    {
        $this->client = new RenisClient($fetcher, $this);
        $this->mapper = new RenisMapper;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'RENIS';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Download,
            termsUrl: 'https://datos.salud.gob.ar/dataset/registro-nacional-de-investigaciones-en-salud-renis',
            licence: 'Creative Commons Attribution 4.0 (reuse, including commercial, with attribution and an indication of changes).',
            attribution: 'Fuente: Registro Nacional de Investigaciones en Salud (RENIS), Ministerio de Salud de la Nación Argentina, datos.salud.gob.ar, CC BY 4.0.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: 10_000,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client;
    }

    public function mapper(): StudyPayloadMapper
    {
        return $this->mapper;
    }

    /** RENIS publishes no page per record (lookups happen inside the SISA application). */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
