import { parseMoney } from './money';
import type { SpecialOrder } from '../types/specialOrders';

/** Remaining customer balance owed (list column). */
export function specialOrderRemainingBalanceCad(row: SpecialOrder): string | null {
    if (!row.customer_price_cad) {
        return null;
    }

    if (row.balance_received_at) {
        return '0.00';
    }

    const price = parseMoney(row.customer_price_cad);
    if (price === null) {
        return null;
    }

    const cash = parseMoney(row.cash_received_cad);
    if (cash !== null && cash > 0) {
        return Math.max(0, price - cash).toFixed(2);
    }

    if (row.balance_cad) {
        return row.balance_cad;
    }

    return row.customer_price_cad;
}

export function specialOrderRemainingBalanceIsPaid(row: SpecialOrder): boolean {
    return row.balance_received_at != null;
}
