<?php

declare(strict_types=1);

use App\Services\Registries\ClinicalTrialsGovSource;

return [

    /*
    | Registries studies are imported from, keyed by the value stored in
    | clinical_trials.source. The order is the priority: when several registries
    | describe the same study, the record of the registry listed first is shown
    | and the others are kept as linked registrations.
    */
    'sources' => [
        ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
    ],

];
