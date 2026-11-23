import { t, tx } from '@/i18n';

/**
 * Readable names for ClinicalTrials.gov vocabulary, in the reader's language, and the position
 * of each recruitment status on the study lifecycle shown by StatusTrack.
 */
export const MAIN_STATUSES = [
    'NOT_YET_RECRUITING',
    'RECRUITING',
    'ENROLLING_BY_INVITATION',
    'ACTIVE_NOT_RECRUITING',
    'COMPLETED',
    'SUSPENDED',
    'TERMINATED',
    'WITHDRAWN',
] as const;

export const PHASES = ['EARLY_PHASE1', 'PHASE1', 'PHASE2', 'PHASE3', 'PHASE4', 'NA'] as const;

export const STUDY_TYPES = ['INTERVENTIONAL', 'OBSERVATIONAL', 'EXPANDED_ACCESS'] as const;

/** Lifecycle stages, as message keys. */
export const LIFECYCLE = [
    'registry.lifecycle.notYetRecruiting',
    'registry.lifecycle.recruiting',
    'registry.lifecycle.active',
    'registry.lifecycle.completed',
] as const;

export type StageTone = 'progress' | 'stopped' | 'unknown';

/**
 * Index of the lifecycle stage reached, and whether the study stopped early.
 */
export function lifecycleStage(status: string | null): { index: number; tone: StageTone } {
    switch (status) {
        case 'NOT_YET_RECRUITING':
            return { index: 0, tone: 'progress' };
        case 'RECRUITING':
        case 'ENROLLING_BY_INVITATION':
            return { index: 1, tone: 'progress' };
        case 'ACTIVE_NOT_RECRUITING':
            return { index: 2, tone: 'progress' };
        case 'COMPLETED':
            return { index: 3, tone: 'progress' };
        case 'SUSPENDED':
            return { index: 1, tone: 'stopped' };
        case 'TERMINATED':
            return { index: 2, tone: 'stopped' };
        case 'WITHDRAWN':
            return { index: 0, tone: 'stopped' };
        default:
            return { index: -1, tone: 'unknown' };
    }
}

export const statusLabel = (status: string | null): string => (status ? tx(`registry.status.${status}`, status) : t('registry.statusNotReported'));

export const phaseLabel = (phases: string[]): string => phases.map((phase) => tx(`registry.phase.${phase}`, phase)).join(' / ');

export const studyTypeLabel = (type: string): string => tx(`registry.studyType.${type}`, type);
