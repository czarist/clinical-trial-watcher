import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { isBusy, setNavigating, trackRequest } from '@/activity';
import LoadingBar from '@/components/LoadingBar.vue';

describe('LoadingBar', () => {
    afterEach(() => {
        setNavigating(false);
        vi.useRealTimers();
    });

    it('counts requests until each one settles, even when it fails', async () => {
        let finish!: () => void;
        const ok = trackRequest(new Promise<void>((resolve) => (finish = resolve)));
        const failed = trackRequest(Promise.reject(new Error('offline'))).catch(() => undefined);

        expect(isBusy.value).toBe(true);
        await failed;
        expect(isBusy.value).toBe(true);
        finish();
        await ok;
        expect(isBusy.value).toBe(false);
    });

    it('shows the bar only when the wait lasts, and hides it when the work ends', async () => {
        vi.useFakeTimers();
        const wrapper = mount(LoadingBar);

        setNavigating(true);
        await nextTick();
        vi.advanceTimersByTime(100);
        await nextTick();
        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);

        vi.advanceTimersByTime(100);
        await nextTick();
        expect(wrapper.find('[role="progressbar"]').exists()).toBe(true);

        setNavigating(false);
        await nextTick();
        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
    });
});
