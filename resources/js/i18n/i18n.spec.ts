import { afterEach, describe, expect, it } from 'vitest';
import { formatPrice, LOCALES, matchLocale, setLocale, t, tx } from '@/i18n';
import en from '@/i18n/locales/en';

const catalogs = import.meta.glob<{ default: Record<string, unknown> }>('./locales/*.ts', { eager: true });

function flatten(node: Record<string, unknown>, prefix = ''): Record<string, string> {
    return Object.entries(node).reduce<Record<string, string>>((all, [key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        return typeof value === 'string' ? { ...all, [path]: value } : { ...all, ...flatten(value as Record<string, unknown>, path) };
    }, {});
}

const placeholders = (text: string): string[] => [...new Set(text.match(/\{\w+\}/g) ?? [])].sort();

describe('i18n', () => {
    afterEach(async () => {
        await setLocale('en');
    });

    it('has a catalog with every key and placeholder for each offered language', () => {
        const english = flatten(en);
        expect(Object.keys(catalogs).sort()).toEqual(LOCALES.map((item) => `./locales/${item.code}.ts`).sort());

        for (const [file, module] of Object.entries(catalogs)) {
            const translated = flatten(module.default);
            expect(Object.keys(translated).sort(), file).toEqual(Object.keys(english).sort());
            for (const [key, text] of Object.entries(english)) {
                expect(placeholders(translated[key] ?? ''), `${file} ${key}`).toEqual(placeholders(text));
            }
        }
    });

    it('fills placeholders and picks plural forms', () => {
        expect(t('search.count', { count: 1 })).toBe('1 study');
        expect(t('search.count', { count: 1200 })).toBe('1,200 studies');
        expect(t('pagination.status', { page: 2, last: 5 })).toBe('Page 2 of 5');
        expect(tx('registry.status.RECRUITING', 'RECRUITING')).toBe('Recruiting');
        expect(tx('registry.status.SOMETHING_NEW', 'SOMETHING_NEW')).toBe('SOMETHING_NEW');
    });

    it('switches language, formats numbers and prices, and sets the page language', async () => {
        await setLocale('ja');
        expect(document.documentElement.lang).toBe('ja');
        expect(t('nav.search')).not.toBe('Search');
        expect(t('search.count', { count: 3 })).not.toContain('|');

        await setLocale('pt_BR');
        expect(formatPrice(19, 'usd')).toContain('19');
        expect(t('search.count', { count: 1200 })).toContain('1.200');
    });

    it('maps browser languages to offered ones', () => {
        expect(matchLocale('pt-PT')).toBe('pt_BR');
        expect(matchLocale('zh-Hant-HK')).toBe('zh_TW');
        expect(matchLocale('zh-CN')).toBe('zh_CN');
        expect(matchLocale('es-419')).toBe('es');
        expect(matchLocale('fr')).toBeNull();
    });
});
