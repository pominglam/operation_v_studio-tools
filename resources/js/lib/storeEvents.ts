import type { ShopifyOrderLine } from '../types/shopifyOrders';
import type { StoreEvent, StoreEventDay, StoreEventDayOrder } from '../types/storeEvent';

export function formatStoreEventDayLabel(date: string): string {
    const parsed = new Date(`${date}T12:00:00`);
    if (Number.isNaN(parsed.getTime())) {
        return date;
    }

    return parsed.toLocaleDateString('en-CA', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function formatStoreEventLineGlance(
    lines: ShopifyOrderLine[] | null,
    fallbackCount: number | null,
): string {
    const items = lines ?? [];
    if (items.length === 0) {
        return fallbackCount !== null && fallbackCount > 0 ? `${fallbackCount} lines` : '—';
    }

    return items
        .slice(0, 3)
        .map((line) => {
            const label = (line.description ?? line.sku ?? 'Item').trim();
            const short = label.length > 42 ? `${label.slice(0, 41)}…` : label;

            return `${line.quantity}× ${short}`;
        })
        .concat(items.length > 3 ? [`+${items.length - 3} more`] : [])
        .join(' · ');
}

function moneyAmount(value: string | null): number {
    if (value === null || value === '' || !Number.isFinite(Number(value))) {
        return 0;
    }

    return Number(value);
}

function moneyString(value: number): string {
    return value.toFixed(2);
}

function recountDay(day: StoreEventDay, orders: StoreEventDayOrder[]): StoreEventDay {
    let eventCount = 0;
    let otherCount = 0;
    let eventSubtotal = 0;
    let otherSubtotal = 0;
    for (const order of orders) {
        const amount = moneyAmount(order.subtotal);
        if (order.in_event) {
            eventCount += 1;
            eventSubtotal += amount;
        } else {
            otherCount += 1;
            otherSubtotal += amount;
        }
    }

    return {
        ...day,
        orders,
        event_order_count: eventCount,
        other_order_count: otherCount,
        order_count: orders.length,
        event_subtotal: moneyString(eventSubtotal),
        other_subtotal: moneyString(otherSubtotal),
    };
}

function recountEvent(event: StoreEvent): StoreEvent {
    const eventOrderCount = event.days.reduce((sum, day) => sum + day.event_order_count, 0);
    const eventSubtotal = event.days.reduce((sum, day) => sum + moneyAmount(day.event_subtotal), 0);

    return {
        ...event,
        event_order_count: eventOrderCount,
        event_subtotal: moneyString(eventSubtotal),
    };
}

export function applyStoreEventInclusion(
    events: StoreEvent[],
    eventId: string,
    orderId: number,
    included: boolean,
): StoreEvent[] {
    const target = events.find((event) => event.id === eventId);

    return events.map((event) => {
        const days = event.days.map((day) => {
            const orders = day.orders.map((order) => {
                if (order.id !== orderId) {
                    return order;
                }
                if (event.id === eventId) {
                    return {
                        ...order,
                        in_event: included,
                        store_event_id: included ? eventId : null,
                        store_event_name: included ? event.name : null,
                    };
                }

                return {
                    ...order,
                    in_event: false,
                    store_event_id: included ? eventId : null,
                    store_event_name: included ? (target?.name ?? null) : null,
                };
            });

            return recountDay(day, orders);
        });

        return recountEvent({ ...event, days });
    });
}
