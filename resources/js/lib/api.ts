import axios, { isAxiosError, type AxiosInstance } from 'axios';

export const api: AxiosInstance = axios.create({
    timeout: 60000,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
    },
});

type ApiErrorBody = {
    message?: string;
    errors?: Record<string, string[] | string>;
    data?: { error_message?: string };
};

/** Prefer Laravel validation / controller message over generic axios status text. */
export function extractApiError(err: unknown): string {
    const data: ApiErrorBody | undefined = isAxiosError(err)
        ? (err.response?.data as ApiErrorBody | undefined)
        : typeof err === 'object' && err !== null && 'response' in err
          ? ((err as { response?: { data?: ApiErrorBody } }).response?.data ?? undefined)
          : undefined;

    const nested = data?.data?.error_message;
    if (typeof nested === 'string' && nested.trim() !== '') {
        return nested;
    }

    if (data?.errors && typeof data.errors === 'object') {
        const flat = Object.values(data.errors)
            .flatMap((value) => (Array.isArray(value) ? value : [value]))
            .filter((line): line is string => typeof line === 'string' && line.trim() !== '');
        if (flat.length > 0) {
            return flat.join(' ');
        }
    }

    if (typeof data?.message === 'string' && data.message.trim() !== '') {
        return data.message;
    }

    return err instanceof Error ? err.message : String(err);
}

/** Strip currency decoration before numeric validation. */
export function normalizeCadMoneyInput(raw: string): string {
    return raw.trim().replace(/^\$/, '').replace(/,/g, '').replace(/\s+/g, '');
}
