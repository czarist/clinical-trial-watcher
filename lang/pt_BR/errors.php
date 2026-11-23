<?php

declare(strict_types=1);

// Mensagens de erro da API em português do Brasil.
return [
    'unauthenticated' => 'Entre na sua conta para continuar.',
    'forbidden' => 'Você não tem permissão para realizar esta ação.',
    'not_found' => 'O item solicitado não foi encontrado.',
    'throttled' => 'Muitas requisições. Aguarde um momento e tente novamente.',

    'alert_limit' => 'Você pode manter até :limit buscas salvas. Exclua uma para criar outra.',

    'billing' => [
        'plan_not_purchasable' => 'Este plano não pode ser comprado online.',
        'no_subscription' => 'Você não tem uma assinatura ativa.',
        'already_subscribed' => 'Você já tem uma assinatura; altere o plano dela.',
        'price_misconfigured' => 'Este plano está temporariamente indisponível para compra. Tente novamente mais tarde.',
        'not_resumable' => 'A assinatura não está no período de carência do cancelamento.',
    ],

    'paid' => [
        'ai_digests' => 'Os digests com IA fazem parte dos planos Pro AI e Team AI. Os alertas básicos continuam gratuitos.',
        'team_workspace' => 'O espaço de trabalho desta equipe precisa de uma assinatura ativa do Team AI em nome do dono da equipe.',
    ],

    'team' => [
        'already_owns_team' => 'Você já é dono de uma equipe. Uma assinatura do Team AI cobre uma equipe.',
        'already_member' => 'Esta pessoa já é membro da equipe.',
        'owner_cannot_leave' => 'O dono da equipe não pode sair dela. Exclua a equipe.',
        'invitation_not_found' => 'Convite não encontrado.',
    ],
];
