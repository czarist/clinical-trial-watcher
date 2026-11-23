<?php

declare(strict_types=1);

// Wording of digests in Spanish. Factual registry language only: no eligibility, treatment
// or efficacy statements.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => 'Activo, sin reclutamiento',
        'COMPLETED' => 'Completado',
        'ENROLLING_BY_INVITATION' => 'Inscripción por invitación',
        'NOT_YET_RECRUITING' => 'Aún no recluta',
        'RECRUITING' => 'Reclutando',
        'SUSPENDED' => 'Suspendido',
        'TERMINATED' => 'Finalizado anticipadamente',
        'WITHDRAWN' => 'Retirado',
        'AVAILABLE' => 'Disponible',
        'NO_LONGER_AVAILABLE' => 'Ya no disponible',
        'TEMPORARILY_NOT_AVAILABLE' => 'Temporalmente no disponible',
        'APPROVED_FOR_MARKETING' => 'Aprobado para comercialización',
        'WITHHELD' => 'Retenido',
        'UNKNOWN' => 'Estado desconocido',
        'none' => 'sin estado',
    ],
    'dates' => [
        'start_date' => 'Fecha de inicio',
        'primary_completion_date' => 'Fecha de finalización primaria',
        'completion_date' => 'Fecha de finalización del estudio',
    ],
    'date_type' => [
        'ACTUAL' => 'real',
        'ESTIMATED' => 'estimada',
    ],
    'months' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    'date_month' => ':month de :year',
    'date_day' => ':day de :month de :year',
    'not_set' => 'sin definir',

    'fact' => [
        'created' => 'Nuevo estudio registrado con el estado :status.',
        'created_no_status' => 'Nuevo estudio registrado.',
        'status' => 'La entrada del registro cambió de :before a :after.',
        'site_added' => 'Centro añadido: :site (:status).',
        'site_added_no_status' => 'Centro añadido: :site.',
        'site_removed' => 'Centro eliminado: :site.',
        'site_updated' => 'El centro :site cambió de :before a :after.',
        'date' => ':name cambió de :before a :after.',
        'results' => 'Se publicaron resultados.',
        'updated' => 'Se actualizó la entrada del registro.',
        'more_sites' => 'Y :count cambios más en centros.',
    ],

    'reason' => [
        'now_recruiting' => 'Se abrió el reclutamiento: el estado cambió a Reclutando.',
        'status_changed' => 'Cambió el estado general.',
        'status_in_criteria' => 'El nuevo estado, :status, es uno de los que sigue esta búsqueda guardada.',
        'site_in_region' => 'Centro que recluta en :place, una ubicación que vigila esta búsqueda guardada.',
        'site_added' => 'Nuevo centro listado.',
        'site_removed' => 'Se eliminó un centro.',
        'results_posted' => 'Se publicaron resultados en el registro.',
        'date_changed' => 'Cambió una fecha clave.',
        'new_study' => 'Estudio recién registrado que coincide con esta búsqueda guardada.',
        'updated' => 'Entrada del registro actualizada.',
        'matches' => 'Coincide con esta búsqueda guardada: :criteria.',
    ],

    'criteria' => [
        'q' => 'texto «:value»',
        'condition' => 'afección «:value»',
        'keyword' => 'palabra clave «:value»',
        'mesh_term' => 'término MeSH «:value»',
        'sponsor' => 'patrocinador «:value»',
        'country' => 'país :value',
        'city' => 'ciudad :value',
        'status' => 'estado :value',
        'phase' => 'fase :value',
    ],

    'subject' => 'Resumen con IA: :name (:period)',
    'subject_fallback' => 'Resumen con IA',
    'title' => 'Resumen con IA de «:name»',
    'period' => ':start a :end',
    'intro' => ':count actualización(es) de estudios coincidentes entre :start y :end.',
    'intro_truncated' => ':count actualización(es) de estudios coincidentes entre :start y :end. A continuación se describen las :shown más relevantes.',
    'source_ai' => 'Resumen redactado por IA a partir de los datos del registro indicados.',
    'source_factual' => 'Resumen elaborado a partir de los cambios registrados en el registro.',
    'updated_on' => 'Actualización del registro publicada el :date',
    'view_registry' => 'Ver en ClinicalTrials.gov',
    'view_report' => 'Abrir el informe',
    'why_matches' => 'Por qué coincide',
    'notice' => 'Este resumen describe entradas públicas del registro ClinicalTrials.gov. No constituye consejo médico, no evalúa la elegibilidad para ningún estudio y no interpreta resultados. Verifique cada dato en la página del registro enlazada.',
    'missing_fact' => 'El registro no proporcionó esta información.',
    'manage' => 'Recibe este mensaje porque los resúmenes con IA están activados para esta búsqueda guardada. Puede desactivarlos en la configuración de la búsqueda guardada; las alertas básicas siguen funcionando.',
];
