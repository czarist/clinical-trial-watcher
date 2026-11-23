import { computed, ref } from 'vue';

/**
 * Work the person is waiting for: API requests in flight and a page being opened.
 * The loading bar at the top of the app shows while any of it is running, so a slow
 * response never looks like a frozen screen.
 */
const requests = ref(0);
const navigating = ref(false);

export const isBusy = computed(() => requests.value > 0 || navigating.value);

export async function trackRequest<T>(work: Promise<T>): Promise<T> {
    requests.value++;
    try {
        return await work;
    } finally {
        requests.value--;
    }
}

export function setNavigating(value: boolean): void {
    navigating.value = value;
}
