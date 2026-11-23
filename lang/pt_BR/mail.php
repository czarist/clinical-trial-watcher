<?php

declare(strict_types=1);

// E-mails enviados aos titulares de contas, em português do Brasil.
return [
    'notice' => 'Estas informações vêm do registro público ClinicalTrials.gov. Não são aconselhamento médico nem uma decisão sobre elegibilidade; converse com um profissional de saúde e com a equipe do estudo.',

    'alert_matches' => [
        'subject' => ':count atualização de estudo para o seu alerta ":name"|:count atualizações de estudos para o seu alerta ":name"',
        'greeting' => 'Alerta: :name',
        'intro' => ':count atualização de estudo corresponde a este alerta.|:count atualizações de estudos correspondem a este alerta.',
        'more' => 'E mais :count.',
        'action' => 'Abrir seus alertas',
        'study_removed' => 'Estudo removido',
        'unknown' => 'desconhecido',
        'reason' => [
            'new_study' => 'Novo estudo',
            'updated_study' => 'Estudo atualizado',
            'status_change' => 'Status alterado',
        ],
    ],

    'team_invitation' => [
        'subject' => 'Convite para a equipe :team',
        'intro' => ':inviter convidou você para a equipe ":team" no Clinical Trial Watcher.',
        'body' => 'Os membros compartilham as buscas salvas e os relatórios de digests com IA da equipe. Nada muda até você aceitar.',
        'action' => 'Ver o convite',
    ],
];
