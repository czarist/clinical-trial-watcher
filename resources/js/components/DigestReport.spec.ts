import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { DigestContent } from '@/api/types';
import DigestReport from '@/components/DigestReport.vue';

const content: DigestContent = {
    name: 'Asthma in Brazil',
    language: 'en',
    title: 'AI digest for "Asthma in Brazil"',
    period: { start: '2026-11-16', end: '2026-11-22', label: 'November 16, 2026 to November 22, 2026' },
    total_updates: 2,
    shown_updates: 2,
    truncated: false,
    intro: '2 matching study update(s) between November 16, 2026 and November 22, 2026.',
    items: [
        {
            rank: 1,
            nct_id: 'NCT01234567',
            title: 'A study',
            url: 'https://clinicaltrials.gov/study/NCT01234567',
            update_date_label: 'November 17, 2026',
            status: 'Recruiting',
            summary: 'The registry entry changed to Recruiting.',
            summary_source: 'ai',
            facts: ['The registry entry changed from Not yet recruiting to Recruiting.'],
            reasons: ['Recruitment opened: the status changed to Recruiting.'],
        },
        {
            rank: 2,
            nct_id: 'NCT07654321',
            title: null,
            url: null,
            update_date_label: null,
            status: null,
            summary: 'Results were posted.',
            summary_source: 'factual',
            facts: ['Results were posted.'],
            reasons: ['Results were posted on the registry.'],
        },
    ],
    labels: {
        source_ai: 'Summary written by AI from the registry facts listed.',
        source_factual: 'Summary built from the recorded registry changes.',
        view_registry: 'View on ClinicalTrials.gov',
        updated_on: 'Registry update posted :date',
        missing_fact: 'The registry did not provide this information.',
    },
    notice: 'This digest is not medical advice.',
    manage: '',
};

describe('DigestReport', () => {
    it('shows each study with its facts, reasons, source and registry link', () => {
        const wrapper = mount(DigestReport, { props: { content } });
        const items = wrapper.findAll('.item');

        expect(items).toHaveLength(2);
        expect(items[0]!.find('a').attributes('href')).toBe('https://clinicaltrials.gov/study/NCT01234567');
        expect(items[0]!.text()).toContain('Registry update posted November 17, 2026');
        expect(items[0]!.text()).toContain('Summary written by AI');
        expect(items[0]!.find('.item__reasons').text()).toContain('Recruitment opened');
        expect(wrapper.text()).toContain('This digest is not medical advice.');
    });

    it('states missing facts instead of inventing them, and shows no link without a validated one', () => {
        const wrapper = mount(DigestReport, { props: { content } });
        const second = wrapper.findAll('.item')[1]!;

        expect(second.find('a').exists()).toBe(false);
        expect(second.find('h3').text()).toContain('The registry did not provide this information.');
        expect(second.text()).toContain('Registry update posted The registry did not provide this information.');
        expect(second.text()).toContain('Summary built from the recorded registry changes.');
    });

    it('labels sample editions', () => {
        const wrapper = mount(DigestReport, { props: { content, sampleLabel: 'Sample edition with synthetic data.' } });

        expect(wrapper.find('.digest__sample').text()).toBe('Sample edition with synthetic data.');
    });
});
