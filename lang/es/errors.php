<?php

declare(strict_types=1);

// Spanish error messages returned by the API.
return [
    'unauthenticated' => 'Inicie sesión para continuar.',
    'forbidden' => 'No tiene permiso para realizar esta acción.',
    'not_found' => 'No se encontró el elemento solicitado.',
    'throttled' => 'Demasiadas solicitudes. Espere un momento e inténtelo de nuevo.',

    'alert_limit' => 'Puede tener hasta :limit búsquedas guardadas. Elimine una para crear otra.',

    'billing' => [
        'plan_not_purchasable' => 'Este plan no se puede comprar en línea.',
        'no_subscription' => 'No tiene una suscripción activa.',
        'already_subscribed' => 'Ya tiene una suscripción; cambie su plan en su lugar.',
        'price_misconfigured' => 'Este plan no está disponible temporalmente para la compra. Inténtelo de nuevo más tarde.',
        'not_resumable' => 'La suscripción no está en su período de gracia de cancelación.',
    ],

    'paid' => [
        'ai_digests' => 'Los resúmenes con IA forman parte de los planes Pro AI y Team AI. Las alertas básicas siguen siendo gratuitas.',
        'team_workspace' => 'El espacio de trabajo de este equipo necesita una suscripción Team AI activa a nombre del propietario del equipo.',
    ],

    'team' => [
        'already_owns_team' => 'Ya es propietario de un equipo. Una suscripción Team AI cubre un equipo.',
        'already_member' => 'Esta persona ya es miembro del equipo.',
        'owner_cannot_leave' => 'El propietario del equipo no puede salir del equipo. En su lugar, elimine el equipo.',
        'invitation_not_found' => 'No se encontró la invitación.',
    ],
];
