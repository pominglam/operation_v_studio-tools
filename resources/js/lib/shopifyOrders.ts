import { formatStaffOrdersRevenue } from './staffOrdersReport';
import type { ShopifyOrderPreorderFilter, ShopifyOrderStatusFilter } from '../types/shopifyOrders';

export function formatShopifyOrderContact(
    email: string | null,
    phone: string | null,
): { email: string | null; phone: string | null } {
    const trimmedEmail = email?.trim() || null;
    const trimmedPhone = phone?.trim() || null;

    return { email: trimmedEmail, phone: trimmedPhone };
}

export function formatShopifyOrderMoney(amount: string | null, currency = 'CAD'): string {
    if (amount === null || amount === '') {
        return '—';
    }

    return formatStaffOrdersRevenue(amount, currency);
}

export function shopifyOrderStatusLabel(status: string | null): string {
    if (!status || status.trim() === '') {
        return '—';
    }

    const normalized = status.replaceAll('_', ' ').toLowerCase();

    return normalized.replace(/\b\w/g, (ch) => ch.toUpperCase());
}

export function torontoDateOffset(days: number, now = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Toronto',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const year = Number(parts.find((part) => part.type === 'year')?.value);
    const month = Number(parts.find((part) => part.type === 'month')?.value);
    const day = Number(parts.find((part) => part.type === 'day')?.value);
    const local = new Date(year, month - 1, day + days);

    const yyyy = String(local.getFullYear());
    const mm = String(local.getMonth() + 1).padStart(2, '0');
    const dd = String(local.getDate()).padStart(2, '0');

    return `${yyyy}-${mm}-${dd}`;
}

export function shopifyOrderDefaultFromDate(): string {
    return torontoDateOffset(-6);
}

/** Wide from-date so store-preorder counts match /orders instead of the 7-day default. */
export function shopifyOrderAllTimeFromDate(): string {
    return '2020-01-01';
}

export function shopifyOrderDefaultUntilDate(): string {
    return torontoDateOffset(0);
}

export function shopifyOrderMonthStart(): string {
    const until = shopifyOrderDefaultUntilDate();
    return `${until.slice(0, 7)}-01`;
}

export function isShopifyOrderStatusFilter(value: string): value is ShopifyOrderStatusFilter {
    return value === 'all' || value === 'eligible' || value === 'cancelled';
}

export function isShopifyOrderPreorderFilter(value: string): value is ShopifyOrderPreorderFilter {
    return value === 'all' || value === 'only';
}

export function shopifyOrderPaymentClass(status: string | null, cancelled: boolean): string {
    if (cancelled) return 'bg-rose-100 text-rose-800';
    const upper = (status ?? '').toUpperCase();
    if (upper === 'PAID') return 'bg-emerald-100 text-emerald-800';
    if (upper === 'PENDING' || upper === 'AUTHORIZED') return 'bg-amber-100 text-amber-800';
    if (upper === 'VOIDED' || upper === 'REFUNDED') return 'bg-rose-100 text-rose-800';
    return 'bg-slate-100 text-slate-700';
}
