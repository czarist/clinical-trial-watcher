import { computed, ref, shallowRef } from 'vue';
import en from '@/i18n/locales/en';

/**
 * The languages the web app is offered in. English is the default and fallback; the other
 * catalogs are loaded when chosen. `code` is the value the API stores (Laravel's locale), `tag`
 * the BCP 47 tag for the browser, Intl and the Accept-Language header.
 */
export const LOCALES = [
    { code: 'en', tag: 'en', name: 'English' },
    { code: 'pt_BR', tag: 'pt-BR', name: 'Português (Brasil)' },
    { code: 'es', tag: 'es', name: 'Español' },
    { code: 'zh_CN', tag: 'zh-CN', name: '简体中文' },
    { code: 'zh_TW', tag: 'zh-TW', name: '繁體中文' },
    { code: 'ja', tag: 'ja', name: '日本語' },
    { code: 'ko', tag: 'ko', name: '한국어' },
] as const;

export type LocaleCode = (typeof LOCALES)[number]['code'];

/** A catalog has the same keys as the English one, with any text. */
export type Messages = Shape<typeof en>;
type Shape<T> = { [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

/** Dotted path of every message, e.g. "search.title". */
export type MessageKey = Paths<typeof en>;
type Paths<T> = { [K in keyof T & string]: T[K] extends string ? K : `${K}.${Paths<T[K]>}` }[keyof T & string];

type Params = Record<string, string | number>;

const STORAGE_KEY = 'ctw.locale';
const loaders = import.meta.glob<{ default: Messages }>(['./locales/*.ts', '!./locales/en.ts']);

const current = ref<LocaleCode>('en');
const catalog = shallowRef<Messages>(en);

export const locale = computed(() => current.value);
export const localeTag = computed(() => tagOf(current.value));

export function tagOf(code: LocaleCode): string {
    return LOCALES.find((item) => item.code === code)?.tag ?? 'en';
}

/** Maps a browser or API language code ("pt-BR", "zh-Hant-TW", "zh_CN") to a supported locale. */
export function matchLocale(code: string | null | undefined): LocaleCode | null {
    if (!code) return null;
    const parts = code.toLowerCase().split(/[-_]/);
    const language = parts[0];
    if (language === 'zh') return parts.some((part) => ['hant', 'tw', 'hk', 'mo'].includes(part)) ? 'zh_TW' : 'zh_CN';
    const map: Record<string, LocaleCode> = { en: 'en', pt: 'pt_BR', es: 'es', ja: 'ja', ko: 'ko' };
    return map[language ?? ''] ?? null;
}

/** The saved choice, else the first supported browser language, else English. */
export function detectLocale(): LocaleCode {
    try {
        const saved = matchLocale(globalThis.localStorage?.getItem(STORAGE_KEY));
        if (saved) return saved;
    } catch {
        // Storage can be unavailable (private mode).
    }
    for (const language of globalThis.navigator?.languages ?? []) {
        const match = matchLocale(language);
        if (match) return match;
    }
    return 'en';
}

async function load(code: LocaleCode): Promise<Messages> {
    if (code === 'en') return en;
    const loader = loaders[`./locales/${code}.ts`];
    return loader ? (await loader()).default : en;
}

/** Switches the language of the whole app and remembers it on this device. */
export async function setLocale(code: LocaleCode): Promise<void> {
    catalog.value = await load(code);
    current.value = code;
    try {
        globalThis.localStorage?.setItem(STORAGE_KEY, code);
    } catch {
        // The choice then lasts for this page only.
    }
    if (typeof document !== 'undefined') document.documentElement.lang = tagOf(code);
}

function lookup(messages: Messages, key: string): string | undefined {
    let node: unknown = messages;
    for (const part of key.split('.')) {
        if (node === null || typeof node !== 'object') return undefined;
        node = (node as Record<string, unknown>)[part];
    }
    return typeof node === 'string' ? node : undefined;
}

/**
 * Picks the plural form ("one|other") for a count, following the language's plural rules;
 * languages without plural forms (Chinese, Japanese, Korean) use a single form.
 */
function plural(text: string, count: number): string {
    const forms = text.split('|');
    if (forms.length === 1) return text;
    const category = new Intl.PluralRules(localeTag.value).select(count);
    return (category === 'one' ? forms[0] : forms[forms.length - 1]) ?? text;
}

function interpolate(text: string, params: Params): string {
    return text.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match));
}

function render(text: string, params?: Params): string {
    if (!params) return text;
    const counted = typeof params.count === 'number' ? plural(text, params.count) : text;
    return interpolate(counted, typeof params.count === 'number' ? { ...params, count: formatNumber(params.count) } : params);
}

/** Translates a message; `{name}` placeholders are filled from params, `{count}` also picks the plural form. */
export function t(key: MessageKey, params?: Params): string {
    return render(lookup(catalog.value, key) ?? lookup(en, key) ?? key, params);
}

/** Translates a key built at run time (for example a registry code), or returns the fallback. */
export function tx(key: string, fallback: string, params?: Params): string {
    const text = lookup(catalog.value, key) ?? lookup(en, key);
    return text === undefined ? fallback : render(text, params);
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat(localeTag.value).format(value);
}

/** A price such as "$19" or "US$19", without decimals when there are none. */
export function formatPrice(amount: number | string, currency: string): string {
    const value = Number(amount);
    return new Intl.NumberFormat(localeTag.value, {
        style: 'currency',
        currency: currency.toUpperCase(),
        minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
    }).format(value);
}

/** A calendar date ("2026-11-17" or an ISO timestamp) in the reader's language. */
export function formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value.length === 10 ? `${value}T00:00:00` : value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(localeTag.value, { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Joins items as a list in the reader's language ("a, b and c"). */
export function formatList(items: string[]): string {
    return new Intl.ListFormat(localeTag.value, { style: 'long', type: 'conjunction' }).format(items);
}
