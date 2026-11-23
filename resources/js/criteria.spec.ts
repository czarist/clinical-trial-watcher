import { describe, expect, it } from 'vitest';
import { toQueryString } from '@/api/client';
import { compactCriteria, criteriaFromQuery, criteriaToQuery } from '@/criteria';

describe('search criteria', () => {
    it('drops empty values before sending or storing', () => {
        expect(compactCriteria({ q: '', condition: 'asthma', status: [], radius_km: Number.NaN, phase: ['PHASE2'] })).toEqual({
            condition: 'asthma',
            phase: ['PHASE2'],
        });
    });

    it('round-trips through the URL', () => {
        const criteria = { condition: 'asthma', status: ['RECRUITING', 'NOT_YET_RECRUITING'], latitude: -23.55, longitude: -46.63, radius_km: 50 };

        expect(criteriaFromQuery(criteriaToQuery(criteria))).toEqual(criteria);
    });

    it('accepts a single list value and ignores unknown keys', () => {
        expect(criteriaFromQuery({ status: 'RECRUITING', page: '2', sort: 'relevance' })).toEqual({ status: ['RECRUITING'] });
    });

    it('builds the query string with array keys the API expects', () => {
        expect(toQueryString({ status: ['RECRUITING', 'COMPLETED'], q: 'asthma', empty: '', none: undefined })).toBe(
            '?status%5B%5D=RECRUITING&status%5B%5D=COMPLETED&q=asthma',
        );
        expect(toQueryString({})).toBe('');
    });
});
