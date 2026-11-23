/**
 * Minimal JSON client for the Laravel API. The JWT is sent as a bearer token.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }

    /** First validation message for a field, if any. */
    field(name: string): string | undefined {
        return this.errors[name]?.[0];
    }
}

type Query = Record<string, string | number | boolean | string[] | undefined | null>;

const TOKEN_KEY = 'ctw.token';

let token: string | null = readToken();
let onUnauthorized: (() => void) | null = null;

function readToken(): string | null {
    try {
        return globalThis.localStorage?.getItem(TOKEN_KEY) ?? null;
    } catch {
        return null;
    }
}

export function setToken(value: string | null): void {
    token = value;
    try {
        if (value === null) localStorage.removeItem(TOKEN_KEY);
        else localStorage.setItem(TOKEN_KEY, value);
    } catch {
        // Storage can be unavailable (private mode); the token then lasts for this page only.
    }
}

export function hasToken(): boolean {
    return token !== null;
}

export function whenUnauthorized(handler: () => void): void {
    onUnauthorized = handler;
}

/** Builds a query string; arrays become key[]=a&key[]=b, empty values are left out. */
export function toQueryString(query: Query): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null || value === '') continue;
        if (Array.isArray(value)) value.forEach((item) => params.append(`${key}[]`, item));
        else params.append(key, String(value));
    }
    const text = params.toString();
    return text === '' ? '' : `?${text}`;
}

export async function request<T>(method: string, path: string, body?: unknown, query: Query = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token !== null) headers.Authorization = `Bearer ${token}`;

    const response = await fetch(`/api${path}${toQueryString(query)}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) return undefined as T;

    const payload = (await response.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };

    if (!response.ok) {
        if (response.status === 401 && token !== null) {
            setToken(null);
            onUnauthorized?.();
        }
        throw new ApiError(response.status, payload.message ?? `Request failed (${response.status}).`, payload.errors);
    }

    return payload as T;
}

export const api = {
    get: <T>(path: string, query?: Query) => request<T>('GET', path, undefined, query),
    post: <T>(path: string, body: unknown = {}) => request<T>('POST', path, body),
    put: <T>(path: string, body: unknown) => request<T>('PUT', path, body),
    patch: <T>(path: string, body: unknown) => request<T>('PATCH', path, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
};
