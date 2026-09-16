import type { ShopifyOrder } from './shopifyOrders';

export type StoreEventDayOrder = ShopifyOrder & {
    in_event: boolean;
};

export type StoreEventDay = {
    date: string;
    event_order_count: number;
    other_order_count: number;
    order_count: number;
    event_subtotal: string;
    other_subtotal: string;
    orders: StoreEventDayOrder[];
};

export type StoreEvent = {
    id: string;
    name: string;
    starts_on: string;
    ends_on: string;
    notes: string | null;
    cancelled: boolean;
    cancelled_at: string | null;
    event_order_count: number;
    event_subtotal: string;
    days: StoreEventDay[];
};

export type StoreEventWrite = {
    name: string;
    starts_on: string;
    ends_on: string;
    notes: string;
    cancelled: boolean;
};
