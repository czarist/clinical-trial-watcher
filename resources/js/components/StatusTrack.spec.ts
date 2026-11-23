import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusTrack from '@/components/StatusTrack.vue';
import { lifecycleStage } from '@/registry';

describe('StatusTrack', () => {
    it('marks the stages reached by a recruiting study', () => {
        const wrapper = mount(StatusTrack, { props: { status: 'RECRUITING' } });

        expect(wrapper.findAll('.is-done')).toHaveLength(1);
        expect(wrapper.find('.is-current').text()).toBe('Recruiting');
        expect(wrapper.find('.track__label').text()).toContain('Recruiting');
        expect(wrapper.classes()).toContain('track--progress');
    });

    it('shows a study that stopped early in the stopped tone', () => {
        const wrapper = mount(StatusTrack, { props: { status: 'TERMINATED' } });

        expect(wrapper.classes()).toContain('track--stopped');
        expect(wrapper.find('.track__label').text()).toContain('Terminated');
    });

    it('handles a missing status without highlighting a stage', () => {
        const wrapper = mount(StatusTrack, { props: { status: null, compact: true } });

        expect(wrapper.find('.is-current').exists()).toBe(false);
        expect(wrapper.find('.track__name').exists()).toBe(false);
        expect(wrapper.text()).toContain('Status not reported');
    });

    it('maps every lifecycle status to a stage', () => {
        expect(lifecycleStage('NOT_YET_RECRUITING').index).toBe(0);
        expect(lifecycleStage('ENROLLING_BY_INVITATION').index).toBe(1);
        expect(lifecycleStage('ACTIVE_NOT_RECRUITING').index).toBe(2);
        expect(lifecycleStage('COMPLETED').index).toBe(3);
        expect(lifecycleStage('WITHDRAWN')).toEqual({ index: 0, tone: 'stopped' });
        expect(lifecycleStage('UNKNOWN').tone).toBe('unknown');
    });
});
