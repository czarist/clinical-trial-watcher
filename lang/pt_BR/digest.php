<?php

declare(strict_types=1);

// Texto dos digests em português do Brasil. Apenas linguagem factual de registro: sem
// afirmações de elegibilidade, tratamento ou eficácia.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => 'Ativo, sem recrutamento',
        'COMPLETED' => 'Concluído',
        'ENROLLING_BY_INVITATION' => 'Inscrição por convite',
        'NOT_YET_RECRUITING' => 'Ainda sem recrutamento',
        'RECRUITING' => 'Recrutando',
        'SUSPENDED' => 'Suspenso',
        'TERMINATED' => 'Encerrado',
        'WITHDRAWN' => 'Retirado',
        'AVAILABLE' => 'Disponível',
        'NO_LONGER_AVAILABLE' => 'Não mais disponível',
        'TEMPORARILY_NOT_AVAILABLE' => 'Temporariamente indisponível',
        'APPROVED_FOR_MARKETING' => 'Aprovado para comercialização',
        'WITHHELD' => 'Retido',
        'UNKNOWN' => 'Status desconhecido',
        'none' => 'sem status',
    ],
    'dates' => [
        'start_date' => 'Data de início',
        'primary_completion_date' => 'Data de conclusão primária',
        'completion_date' => 'Data de conclusão do estudo',
    ],
    'date_type' => [
        'ACTUAL' => 'real',
        'ESTIMATED' => 'estimada',
    ],
    'months' => ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'],
    'date_month' => ':month de :year',
    'date_day' => ':day de :month de :year',
    'not_set' => 'não informada',

    'fact' => [
        'created' => 'Novo estudo registrado com o status :status.',
        'created_no_status' => 'Novo estudo registrado.',
        'status' => 'O registro mudou de :before para :after.',
        'site_added' => 'Centro adicionado: :site (:status).',
        'site_added_no_status' => 'Centro adicionado: :site.',
        'site_removed' => 'Centro removido: :site.',
        'site_updated' => 'O centro :site mudou de :before para :after.',
        'date' => ':name mudou de :before para :after.',
        'results' => 'Os resultados foram publicados.',
        'updated' => 'O registro foi atualizado.',
        'more_sites' => 'Mais :count alterações de centros.',
    ],

    'reason' => [
        'now_recruiting' => 'Recrutamento aberto: o status mudou para Recrutando.',
        'status_changed' => 'O status geral mudou.',
        'status_in_criteria' => 'O novo status, :status, é um dos que esta busca salva acompanha.',
        'site_in_region' => 'Centro recrutando em :place, um local que esta busca salva monitora.',
        'site_added' => 'Novo centro listado.',
        'site_removed' => 'Um centro foi removido.',
        'results_posted' => 'Os resultados foram publicados no registro.',
        'date_changed' => 'Uma data importante mudou.',
        'new_study' => 'Estudo recém-registrado que corresponde a esta busca salva.',
        'updated' => 'Registro atualizado.',
        'matches' => 'Corresponde a esta busca salva: :criteria.',
    ],

    'criteria' => [
        'q' => 'texto ":value"',
        'condition' => 'condição ":value"',
        'keyword' => 'palavra-chave ":value"',
        'mesh_term' => 'termo MeSH ":value"',
        'sponsor' => 'patrocinador ":value"',
        'country' => 'país :value',
        'city' => 'cidade :value',
        'status' => 'status :value',
        'phase' => 'fase :value',
    ],

    'subject' => 'Digest com IA: :name (:period)',
    'subject_fallback' => 'Digest com IA',
    'title' => 'Digest com IA de ":name"',
    'period' => ':start a :end',
    'intro' => ':count atualização(ões) de estudos correspondentes entre :start e :end.',
    'intro_truncated' => ':count atualização(ões) de estudos correspondentes entre :start e :end. As :shown mais relevantes estão descritas abaixo.',
    'source_ai' => 'Resumo escrito por IA a partir dos fatos do registro listados.',
    'source_factual' => 'Resumo montado a partir das alterações registradas.',
    'updated_on' => 'Atualização publicada no registro em :date',
    'view_registry' => 'Ver no ClinicalTrials.gov',
    'view_report' => 'Abrir o relatório',
    'why_matches' => 'Por que corresponde',
    'notice' => 'Este digest descreve registros públicos do ClinicalTrials.gov. Não é aconselhamento médico, não avalia elegibilidade para nenhum estudo e não interpreta resultados. Confira cada fato na página do registro indicada.',
    'missing_fact' => 'O registro não informou este dado.',
    'manage' => 'Você recebe este e-mail porque os digests com IA estão ativados nesta busca salva. Você pode desativá-los nas configurações da busca; os alertas básicos continuam funcionando.',
];
