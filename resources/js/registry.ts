/**
 * Readable names for ClinicalTrials.gov vocabulary, and the position of each recruitment
 * status on the study lifecycle shown by StatusTrack.
 */
export const STATUS_LABELS: Record<string, string> = {
    NOT_YET_RECRUITING: 'Not yet recruiting',
    RECRUITING: 'Recruiting',
    ENROLLING_BY_INVITATION: 'Enrolling by invitation',
    ACTIVE_NOT_RECRUITING: 'Active, not recruiting',
    COMPLETED: 'Completed',
    SUSPENDED: 'Suspended',
    TERMINATED: 'Terminated',
    WITHDRAWN: 'Withdrawn',
    AVAILABLE: 'Available',
    NO_LONGER_AVAILABLE: 'No longer available',
    TEMPORARILY_NOT_AVAILABLE: 'Temporarily not available',
    APPROVED_FOR_MARKETING: 'Approved for marketing',
    WITHHELD: 'Withheld',
    UNKNOWN: 'Unknown status',
};

export const PHASE_LABELS: Record<string, string> = {
    EARLY_PHASE1: 'Early phase 1',
    PHASE1: 'Phase 1',
    PHASE2: 'Phase 2',
    PHASE3: 'Phase 3',
    PHASE4: 'Phase 4',
    NA: 'Not applicable',
};

export const STUDY_TYPE_LABELS: Record<string, string> = {
    INTERVENTIONAL: 'Interventional',
    OBSERVATIONAL: 'Observational',
    EXPANDED_ACCESS: 'Expanded access',
};

export const LIFECYCLE = ['Not yet recruiting', 'Recruiting', 'Active', 'Completed'] as const;

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

export const statusLabel = (status: string | null): string => (status ? (STATUS_LABELS[status] ?? status) : 'Status not reported');

export const phaseLabel = (phases: string[]): string => phases.map((phase) => PHASE_LABELS[phase] ?? phase).join(' / ');

export function formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value.length === 10 ? `${value}T00:00:00` : value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export const DISCLAIMER =
    'Registry data from ClinicalTrials.gov. It is not medical advice or a decision about eligibility — talk to a health professional and the study team.';
