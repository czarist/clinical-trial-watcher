<?php

declare(strict_types=1);

// Spanish emails sent to account holders.
return [
    'notice' => 'Esta información procede del registro público ClinicalTrials.gov. No constituye consejo médico ni una decisión sobre elegibilidad; consulte a un profesional de la salud y al equipo del estudio.',

    'alert_matches' => [
        'subject' => ':count actualización de estudio para su alerta «:name»|:count actualizaciones de estudios para su alerta «:name»',
        'greeting' => 'Alerta: :name',
        'intro' => ':count actualización de estudio coincide con esta alerta.|:count actualizaciones de estudios coinciden con esta alerta.',
        'more' => 'Y :count más.',
        'action' => 'Abrir sus alertas',
        'study_removed' => 'Estudio eliminado',
        'unknown' => 'desconocido',
        'reason' => [
            'new_study' => 'Estudio nuevo',
            'updated_study' => 'Estudio actualizado',
            'status_change' => 'Cambio de estado',
        ],
    ],

    'team_invitation' => [
        'subject' => 'Invitación al equipo :team',
        'intro' => ':inviter le invitó al equipo «:team» en Clinical Trial Watcher.',
        'body' => 'Los miembros comparten las búsquedas guardadas y los informes de resúmenes con IA del equipo. Nada cambia hasta que acepte.',
        'action' => 'Revisar la invitación',
    ],
];
