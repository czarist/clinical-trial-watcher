<?php

declare(strict_types=1);

use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\Sources\Anzctr\AnzctrSource;
use App\Services\Registries\Sources\Cris\CrisSource;
use App\Services\Registries\Sources\Ctis\CtisSource;
use App\Services\Registries\Sources\Drks\DrksSource;
use App\Services\Registries\Sources\EdaEgypt\EdaEgyptSource;
use App\Services\Registries\Sources\Euctr\EuctrSource;
use App\Services\Registries\Sources\HealthCanada\HealthCanadaSource;
use App\Services\Registries\Sources\Hra\HraSource;
use App\Services\Registries\Sources\Isrctn\IsrctnSource;
use App\Services\Registries\Sources\Mfds\MfdsSource;
use App\Services\Registries\Sources\MozUkraine\MozUkraineSource;
use App\Services\Registries\Sources\NciCts\NciCtsSource;
use App\Services\Registries\Sources\Orphanet\OrphanetSource;
use App\Services\Registries\Sources\PpbKenya\PpbKenyaSource;
use App\Services\Registries\Sources\Rebec\RebecSource;
use App\Services\Registries\Sources\Reec\ReecSource;
use App\Services\Registries\Sources\Renis\RenisSource;
use App\Services\Registries\Sources\Sukl\SuklSource;
use App\Services\Registries\Sources\Tfda\TfdaSource;
use App\Services\Registries\Sources\Umin\UminSource;

return [

    /*
    | Registries studies are imported from, keyed by the value stored in
    | clinical_trials.source. The order is the priority: when several registries
    | describe the same study, the record of the registry listed first is shown
    | and the others are kept as linked registrations.
    |
    | Each source records its legal review in RegistrySource::access() (see
    | docs/registries-evaluation.md, section 7). Only ClinicalTrials.gov is
    | scheduled; import the others with `clinicaltrials:fetch --source=<key>`.
    */
    'sources' => [
        ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
        CtisSource::KEY => CtisSource::class,
        IsrctnSource::KEY => IsrctnSource::class,
        EuctrSource::KEY => EuctrSource::class,
        DrksSource::KEY => DrksSource::class,
        ReecSource::KEY => ReecSource::class,
        RebecSource::KEY => RebecSource::class,
        RenisSource::KEY => RenisSource::class,
        HealthCanadaSource::KEY => HealthCanadaSource::class,
        TfdaSource::KEY => TfdaSource::class,
        PpbKenyaSource::KEY => PpbKenyaSource::class,
        EdaEgyptSource::KEY => EdaEgyptSource::class,
        MozUkraineSource::KEY => MozUkraineSource::class,
        // Need an operator key.
        NciCtsSource::KEY => NciCtsSource::class,
        MfdsSource::KEY => MfdsSource::class,
        CrisSource::KEY => CrisSource::class,
        // Need the registry's written permission (see 'permissions' below).
        AnzctrSource::KEY => AnzctrSource::class,
        UminSource::KEY => UminSource::class,
        HraSource::KEY => HraSource::class,
        SuklSource::KEY => SuklSource::class,
        OrphanetSource::KEY => OrphanetSource::class,
    ],

    /*
    | Written permissions. Some registries allow automated access or reuse only with
    | their written permission (see docs/registries-evaluation.md, section 7). Their
    | importers are built but refuse every request until the permission is recorded
    | here. Set a variable to true only after the registry granted it in writing, and
    | keep that document with the project's records.
    */
    'permissions' => [
        'hra' => (bool) env('REGISTRY_PERMISSION_HRA', false),
        'anzctr' => (bool) env('REGISTRY_PERMISSION_ANZCTR', false),
        'sukl' => (bool) env('REGISTRY_PERMISSION_SUKL', false),
        'orphanet' => (bool) env('REGISTRY_PERMISSION_ORPHANET', false),
        'umin' => (bool) env('REGISTRY_PERMISSION_UMIN', false),
    ],

    /*
    | Per-source settings. Every value has a default in its source class; set
    | these only to point an importer elsewhere, e.g. to a newer file when a
    | registry publishes it under a new address.
    */
    'options' => [
        'renis' => ['file_url' => env('REGISTRY_RENIS_FILE_URL')],
        'eda_egypt' => ['pdf_url' => env('REGISTRY_EDA_EGYPT_PDF_URL')],
        'nci_cts' => ['api_key' => env('REGISTRY_NCI_CTS_API_KEY')],
        'mfds' => ['service_key' => env('REGISTRY_MFDS_SERVICE_KEY', '')],
        'cris' => ['service_key' => env('REGISTRY_CRIS_SERVICE_KEY', '')],
        // Delivered under an Orphanet-AISBL contract; no public address.
        'orphanet' => ['dump_url' => env('REGISTRY_ORPHANET_DUMP_URL', '')],
    ],

    /*
    | Requests to registries read by API, download or web page (RegistryFetcher).
    | The user agent identifies the application and carries REGISTRY_CONTACT, a URL
    | or email address the registry's operators can use to reach you; reading by
    | download or scraping is refused while it is empty. Requests to one registry
    | are made one at a time with at least min_interval_ms between them (more when a
    | source or its robots.txt Crawl-delay asks for it), and retried a bounded number
    | of times. Every body is stored on the disk below so unchanged pages are not
    | downloaded again and `clinicaltrials:fetch --from-cache` can parse them again.
    */
    'fetching' => [
        'user_agent' => env('REGISTRY_USER_AGENT', 'ClinicalTrialWatcher/1.0'),
        'contact' => env('REGISTRY_CONTACT'),
        'min_interval_ms' => (int) env('REGISTRY_MIN_INTERVAL_MS', 2000),
        'timeout' => (int) env('REGISTRY_TIMEOUT', 60),
        'retry' => [
            'times' => (int) env('REGISTRY_RETRY_TIMES', 3),
            'sleep_ms' => (int) env('REGISTRY_RETRY_SLEEP_MS', 2000),
            'max_sleep_ms' => (int) env('REGISTRY_RETRY_MAX_SLEEP_MS', 60000),
        ],
        'robots_cache_hours' => (int) env('REGISTRY_ROBOTS_CACHE_HOURS', 24),
        'disk' => env('REGISTRY_RAW_DISK', 'local'),
        'directory' => 'registry-raw',
    ],

    /*
    | Layout-drift check for registries read by download or web page: a full run that
    | parses fewer than record_ratio of the records of the last comparable successful
    | run (when that run had at least min_previous_records), or whose share of filled
    | fields drops by more than fill_ratio_drop, is marked partial and logged. Imports
    | never delete studies, so a broken parser cannot remove data.
    */
    'drift' => [
        'min_previous_records' => 20,
        'record_ratio' => 0.5,
        'fill_ratio_drop' => 0.25,
    ],

];
