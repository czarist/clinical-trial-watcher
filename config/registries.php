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
    | Guidance shown to operators in the admin panel (Admin > Registry access) on
    | getting access to restricted data: whom to contact, which key or written
    | permission each registry needs, and registries that cannot be imported yet.
    | Draft messages for every request are in docs/registry-access-requests.md.
    | "confirm" marks registries imported under their published terms whose
    | written confirmation is still advisable.
    */
    'guidance' => [
        'ctis' => ['contact' => 'https://www.ema.europa.eu/en/about-us/contacts-european-medicines-agency', 'confirm' => true],
        'euctr' => ['contact' => 'https://www.ema.europa.eu/en/about-us/contacts-european-medicines-agency', 'confirm' => true],
        'isrctn' => ['contact' => 'mailto:info@isrctn.com', 'confirm' => true],
        'drks' => ['contact' => 'mailto:drks@bfarm.de', 'confirm' => true],
        'rebec' => ['contact' => 'https://ensaiosclinicos.gov.br/'],
        'ppb_kenya' => ['contact' => 'mailto:cta@pharmacyboardkenya.org'],
        'eda_egypt' => ['contact' => 'https://www.edaegypt.gov.eg/'],
        'moz_ukraine' => ['contact' => 'https://moz.gov.ua/'],
        'nci_cts' => ['contact' => 'https://clinicaltrialsapi.cancer.gov/', 'key' => ['config' => 'registries.options.nci_cts.api_key', 'env' => 'REGISTRY_NCI_CTS_API_KEY']],
        'mfds' => ['contact' => 'https://www.data.go.kr/', 'key' => ['config' => 'registries.options.mfds.service_key', 'env' => 'REGISTRY_MFDS_SERVICE_KEY']],
        'cris' => ['contact' => 'https://www.data.go.kr/', 'key' => ['config' => 'registries.options.cris.service_key', 'env' => 'REGISTRY_CRIS_SERVICE_KEY']],
        'anzctr' => ['contact' => 'https://www.anzctr.org.au/', 'permission' => 'REGISTRY_PERMISSION_ANZCTR'],
        'umin' => ['contact' => 'https://www.umin.ac.jp/ctr/', 'permission' => 'REGISTRY_PERMISSION_UMIN'],
        'hra' => ['contact' => 'https://www.hra.nhs.uk/', 'permission' => 'REGISTRY_PERMISSION_HRA'],
        'sukl' => ['contact' => 'https://www.sukl.cz/', 'permission' => 'REGISTRY_PERMISSION_SUKL'],
        'orphanet' => ['contact' => 'https://www.orphadata.com/', 'permission' => 'REGISTRY_PERMISSION_ORPHANET', 'settings' => ['REGISTRY_ORPHANET_DUMP_URL' => 'registries.options.orphanet.dump_url']],
    ],

    /*
    | Registries surveyed that cannot be imported yet. reason: robots (robots.txt
    | disallows the data), anti_bot (a bot challenge on every page), firewall,
    | offline, duplicate (the same trials come from another registry) or
    | missing_fields (public pages lack identifiers, phase or status).
    */
    'unavailable' => [
        ['name' => 'WHO ICTRP', 'reason' => 'robots', 'contact' => 'https://www.who.int/tools/clinical-trials-registry-platform'],
        ['name' => 'HMA-EMA catalogue of real-world data studies', 'reason' => 'robots', 'contact' => 'https://catalogues.ema.europa.eu/'],
        ['name' => 'CDE/NMPA (China)', 'reason' => 'anti_bot', 'contact' => 'https://www.cde.org.cn/'],
        ['name' => 'TİTCK Clinical Research Portal (Turkey)', 'reason' => 'firewall', 'contact' => 'https://www.titck.gov.tr/'],
        ['name' => 'INCa cancer trials registry (France)', 'reason' => 'offline', 'contact' => 'https://www.cancer.fr/'],
        ['name' => 'RNEC (Portugal)', 'reason' => 'duplicate', 'contact' => 'https://www.infarmed.pt/'],
        ['name' => 'Plataforma Brasil (CEP/Conep)', 'reason' => 'missing_fields', 'contact' => 'https://conselho.saude.gov.br/'],
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
