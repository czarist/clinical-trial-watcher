import type { LocationQuery } from 'vue-router';
import type { SearchCriteria } from '@/api/types';

const LIST_KEYS = ['status', 'phase', 'study_type'] as const;
const NUMBER_KEYS = ['latitude', 'longitude', 'radius_km'] as const;
const TEXT_KEYS = [
    'q',
    'condition',
    'keyword',
    'mesh_term',
    'sponsor',
    'country',
    'city',
    'start_date_from',
    'start_date_to',
    'last_update_from',
    'last_update_to',
] as const;

/** Removes empty values so they are neither sent to the API nor stored in an alert. */
export function compactCriteria(criteria: SearchCriteria): SearchCriteria {
    return Object.fromEntries(
        Object.entries(criteria).filter(
            ([, value]) => value !== undefined && value !== null && value !== '' && !(typeof value === 'number' && Number.isNaN(value)) && !(Array.isArray(value) && value.length === 0),
        ),
    ) as SearchCriteria;
}

/** Reads criteria from the URL so a search can be bookmarked and shared. */
export function criteriaFromQuery(query: LocationQuery): SearchCriteria {
    const criteria: SearchCriteria = {};
    const first = (value: unknown): string | undefined => (Array.isArray(value) ? value[0] : value) as string | undefined;

    for (const key of TEXT_KEYS) {
        const value = first(query[key]);
        if (value) criteria[key] = value;
    }
    for (const key of NUMBER_KEYS) {
        const value = Number(first(query[key]));
        if (first(query[key]) !== undefined && !Number.isNaN(value)) criteria[key] = value;
    }
    for (const key of LIST_KEYS) {
        const value = query[key];
        if (value !== undefined && value !== null) criteria[key] = (Array.isArray(value) ? value : [value]).filter((item): item is string => typeof item === 'string');
    }

    return compactCriteria(criteria);
}

export function criteriaToQuery(criteria: SearchCriteria): Record<string, string | string[]> {
    return Object.fromEntries(
        Object.entries(compactCriteria(criteria)).map(([key, value]) => [key, Array.isArray(value) ? value : String(value)]),
    );
}
