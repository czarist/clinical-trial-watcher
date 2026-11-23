import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, hasToken, setToken } from '@/api/client';
import type { BillingSummary, User } from '@/api/types';
import { locale, matchLocale, setLocale, type LocaleCode } from '@/i18n';

interface TokenResponse {
    access_token: string;
    expires_in: number;
}

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null);
    const billing = ref<BillingSummary | null>(null);
    const loaded = ref(false);

    const isLoggedIn = computed(() => user.value !== null);
    const can = (permission: string): boolean => user.value?.permissions.includes(permission) ?? false;
    const isAdmin = computed(() => ['manage_users', 'manage_plans', 'manage_roles', 'manage_imports'].some(can));

    async function load(): Promise<void> {
        if (!hasToken()) {
            loaded.value = true;
            return;
        }
        try {
            const response = await api.get<{ data: User; billing: BillingSummary }>('/auth/me');
            user.value = response.data;
            billing.value = response.billing;
            // The account's language follows the user from device to device.
            const saved = matchLocale(response.data.locale);
            if (saved && saved !== locale.value) await setLocale(saved);
        } catch {
            clear();
        } finally {
            loaded.value = true;
        }
    }

    async function login(email: string, password: string): Promise<void> {
        const response = await api.post<TokenResponse>('/auth/login', { email, password });
        setToken(response.access_token);
        await load();
    }

    async function register(name: string, email: string, password: string, passwordConfirmation: string): Promise<void> {
        const response = await api.post<TokenResponse>('/auth/register', {
            name,
            email,
            password,
            password_confirmation: passwordConfirmation,
            locale: locale.value,
        });
        setToken(response.access_token);
        await load();
    }

    /** Changes the language now and, when logged in, for the account and its emails. */
    async function changeLocale(code: LocaleCode): Promise<void> {
        await setLocale(code);
        if (user.value !== null) {
            user.value = (await api.put<{ data: User }>('/account/locale', { locale: code })).data;
        }
    }

    async function logout(): Promise<void> {
        try {
            await api.post('/auth/logout');
        } finally {
            clear();
        }
    }

    function clear(): void {
        setToken(null);
        user.value = null;
        billing.value = null;
    }

    return { user, billing, loaded, isLoggedIn, isAdmin, can, load, login, register, changeLocale, logout, clear };
});
