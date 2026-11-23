<?php

declare(strict_types=1);

namespace App\Services\Digests;

/**
 * Two sample digest editions with synthetic data, in the same shape as a real edition's
 * content. The studies, identifiers and sites are invented, so the samples have no registry
 * links; real editions link every study to its ClinicalTrials.gov page.
 */
final class DigestSamples
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [$this->proSample(), $this->teamSample()];
    }

    /**
     * @return array<string, mixed>
     */
    private function proSample(): array
    {
        return [
            'id' => 'sample-pro-ai',
            'sample' => true,
            'plan' => 'Pro AI',
            'sample_label' => 'Sample edition with synthetic data. The studies, identifiers and sites are invented.',
            'name' => 'Asthma studies in Brazil',
            'language' => 'en',
            'title' => 'AI digest for "Asthma studies in Brazil"',
            'period' => ['start' => '2026-11-09', 'end' => '2026-11-15', 'label' => 'November 9, 2026 to November 15, 2026'],
            'total_updates' => 4,
            'shown_updates' => 3,
            'truncated' => true,
            'intro' => '4 matching study update(s) between November 9, 2026 and November 15, 2026. The 3 most relevant are described below.',
            'items' => [
                [
                    'rank' => 1,
                    'nct_id' => 'SAMPLE-0001',
                    'title' => 'Inhaled Therapy Comparison in Adults With Moderate Asthma (synthetic example)',
                    'url' => null,
                    'update_date_label' => 'November 12, 2026',
                    'status' => 'Recruiting',
                    'summary' => 'The registry entry changed from Not yet recruiting to Recruiting. A site in São Paulo, Brazil, is now listed as recruiting.',
                    'summary_source' => 'ai',
                    'facts' => [
                        'The registry entry changed from Not yet recruiting to Recruiting.',
                        'Site added: Example Hospital, São Paulo, Brazil (Recruiting).',
                    ],
                    'reasons' => [
                        'Recruitment opened: the status changed to Recruiting.',
                        'Recruiting site in Brazil, a location this saved search monitors.',
                        'Matches this saved search: condition "asthma", country Brazil.',
                    ],
                ],
                [
                    'rank' => 2,
                    'nct_id' => 'SAMPLE-0002',
                    'title' => 'Long-Term Follow-Up of a Biologic Treatment in Severe Asthma (synthetic example)',
                    'url' => null,
                    'update_date_label' => 'November 10, 2026',
                    'status' => 'Completed',
                    'summary' => 'Results were posted on the registry. Posted results describe what the study measured; they are not a conclusion about the treatment.',
                    'summary_source' => 'ai',
                    'facts' => ['Results were posted.'],
                    'reasons' => [
                        'Results were posted on the registry.',
                        'Matches this saved search: condition "asthma", country Brazil.',
                    ],
                ],
                [
                    'rank' => 3,
                    'nct_id' => 'SAMPLE-0003',
                    'title' => 'Asthma Control in Children Using a Digital Inhaler (synthetic example)',
                    'url' => null,
                    'update_date_label' => 'November 14, 2026',
                    'status' => 'Recruiting',
                    'summary' => 'The primary completion date changed from June 2027 (estimated) to March 2028 (estimated). The registry gives these dates to the month only.',
                    'summary_source' => 'factual',
                    'facts' => ['Primary completion date changed from June 2027 (estimated) to March 2028 (estimated).'],
                    'reasons' => [
                        'A key date changed.',
                        'Matches this saved search: condition "asthma", country Brazil.',
                    ],
                ],
            ],
            'labels' => [
                'source_ai' => 'Summary written by AI from the registry facts listed.',
                'source_factual' => 'Summary built from the recorded registry changes.',
                'view_registry' => 'View on ClinicalTrials.gov',
                'updated_on' => 'Registry update posted :date',
                'missing_fact' => 'The registry did not provide this information.',
            ],
            'notice' => trans('digest.notice', [], 'en'),
            'manage' => trans('digest.manage', [], 'en'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamSample(): array
    {
        return [
            'id' => 'sample-team-ai',
            'sample' => true,
            'plan' => 'Team AI',
            'sample_label' => 'Edição de exemplo com dados sintéticos. Os estudos, identificadores e centros são inventados.',
            'name' => 'Oncologia: estudos de fase 3 no Sul do Brasil',
            'language' => 'pt_BR',
            'title' => 'Digest com IA de "Oncologia: estudos de fase 3 no Sul do Brasil"',
            'period' => ['start' => '2026-11-16', 'end' => '2026-11-16', 'label' => '16 de novembro de 2026 a 16 de novembro de 2026'],
            'total_updates' => 2,
            'shown_updates' => 2,
            'truncated' => false,
            'intro' => '2 atualização(ões) de estudos correspondentes entre 16 de novembro de 2026 e 16 de novembro de 2026.',
            'items' => [
                [
                    'rank' => 1,
                    'nct_id' => 'SAMPLE-0101',
                    'title' => 'Combination Therapy in Advanced Non-Small Cell Lung Cancer (exemplo sintético)',
                    'url' => null,
                    'update_date_label' => '16 de novembro de 2026',
                    'status' => 'Recrutando',
                    'summary' => 'Um centro em Porto Alegre, Brasil, passou de Ainda sem recrutamento para Recrutando no registro.',
                    'summary_source' => 'ai',
                    'facts' => ['O centro Hospital Exemplo, Porto Alegre, Rio Grande do Sul, Brazil mudou de Ainda sem recrutamento para Recrutando.'],
                    'reasons' => [
                        'Centro recrutando em Porto Alegre, Brazil, um local que esta busca salva monitora.',
                        'Corresponde a esta busca salva: condição "lung cancer", país Brazil, fase PHASE3.',
                    ],
                ],
                [
                    'rank' => 2,
                    'nct_id' => 'SAMPLE-0102',
                    'title' => 'Adjuvant Treatment After Surgery for Colorectal Cancer (exemplo sintético)',
                    'url' => null,
                    'update_date_label' => null,
                    'status' => 'Ativo, sem recrutamento',
                    'summary' => 'O registro mudou de Recrutando para Ativo, sem recrutamento.',
                    'summary_source' => 'factual',
                    'facts' => ['O registro mudou de Recrutando para Ativo, sem recrutamento.'],
                    'reasons' => [
                        'O status geral mudou.',
                        'Corresponde a esta busca salva: condição "colorectal cancer", país Brazil, fase PHASE3.',
                    ],
                ],
            ],
            'labels' => [
                'source_ai' => 'Resumo escrito por IA a partir dos fatos do registro listados.',
                'source_factual' => 'Resumo montado a partir das alterações registradas.',
                'view_registry' => 'Ver no ClinicalTrials.gov',
                'updated_on' => 'Atualização publicada no registro em :date',
                'missing_fact' => 'O registro não informou este dado.',
            ],
            'notice' => trans('digest.notice', [], 'pt_BR'),
            'manage' => trans('digest.manage', [], 'pt_BR'),
        ];
    }
}
