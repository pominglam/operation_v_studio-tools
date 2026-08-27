import { parseMoney } from './money';

export const CUSTOM_ASIA_ORDER_CUSTOMER_MESSAGE_PLACEHOLDERS = [
    '{product_name}',
    '{price}',
    '{deposit_percent}',
] as const;

export const DEFAULT_CUSTOM_ASIA_ORDER_CUSTOMER_MESSAGE_TEMPLATE = `{product_name} — Special Order

Price: {price}

We source from reputable stores and ship with a reliable carrier. Boxes normally arrive in good condition, though shipping wear is always possible. Significant box damage would be very unusual and we haven't encountered it so far, but box condition cannot be guaranteed.
Missing or damaged parts are extremely unlikely. The kit must be inspected at pickup, before leaving the store. If there is an issue, a discount of up to 10% may be offered at our discretion.
A {deposit_percent}% non-refundable deposit is required to place the order. Once ordered, the deposit is forfeited if you decide not to complete the purchase for any reason.

Please confirm you're okay with these conditions and I'll place the order.`;

export function formatCustomAsiaOrderCustomerPriceLabel(
    customerPriceCad: string | number | null | undefined,
): string | null {
    const amount = parseMoney(customerPriceCad);
    if (amount === null || amount <= 0) {
        return null;
    }

    const formatted = Number.isInteger(amount) ? String(amount) : amount.toFixed(2);

    return `$${formatted} CAD`;
}

function formatDepositPercentLabel(depositPercent: string | number | null | undefined): string | null {
    const raw = depositPercent === null || depositPercent === undefined ? '' : String(depositPercent).trim();
    if (raw === '') {
        return null;
    }

    const value = Number(raw);
    if (Number.isNaN(value) || value < 0) {
        return null;
    }

    if (value === 0) {
        return '0';
    }

    return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
}

function formatDepositPercentFromAmount(
    customerPriceCad: string | number | null | undefined,
    depositAmountOverrideCad: string | number | null | undefined,
): string | null {
    const price = parseMoney(customerPriceCad);
    const depositAmount = parseMoney(depositAmountOverrideCad ?? null);
    if (price === null || depositAmount === null || price <= 0 || depositAmount < 0) {
        return null;
    }

    if (depositAmount === 0) {
        return '0';
    }

    return formatDepositPercentLabel((depositAmount / price) * 100);
}

export function resolveDepositPercentLabel(input: {
    depositPercent: string | number | null | undefined;
    customerPriceCad: string | number | null | undefined;
    depositAmountOverrideCad?: string | number | null | undefined;
}): string | null {
    const fromPercent = formatDepositPercentLabel(input.depositPercent);
    if (fromPercent !== null) {
        return fromPercent;
    }

    return formatDepositPercentFromAmount(input.customerPriceCad, input.depositAmountOverrideCad);
}

export function renderCustomAsiaOrderCustomerMessage(
    template: string,
    input: {
        productName: string;
        customerPriceCad: string | number | null | undefined;
        depositPercent: string | number | null | undefined;
        depositAmountOverrideCad?: string | number | null | undefined;
    },
): string | null {
    const productName = input.productName.trim();
    const priceLabel = formatCustomAsiaOrderCustomerPriceLabel(input.customerPriceCad);
    const depositLabel =
        resolveDepositPercentLabel({
            depositPercent: input.depositPercent,
            customerPriceCad: input.customerPriceCad,
            depositAmountOverrideCad: input.depositAmountOverrideCad,
        }) ?? '0';

    if (productName === '' || priceLabel === null) {
        return null;
    }

    return template
        .replaceAll('{product_name}', productName)
        .replaceAll('{price}', priceLabel)
        .replaceAll('{deposit_percent}', depositLabel);
}

export function buildCustomAsiaOrderCustomerMessage(input: {
    template?: string | null;
    productName: string;
    customerPriceCad: string | number | null | undefined;
    depositPercent: string | number | null | undefined;
    depositAmountOverrideCad?: string | number | null | undefined;
}): string | null {
    const template =
        input.template?.trim() || DEFAULT_CUSTOM_ASIA_ORDER_CUSTOMER_MESSAGE_TEMPLATE;

    return renderCustomAsiaOrderCustomerMessage(template, input);
}

export function previewCustomAsiaOrderCustomerMessage(template: string): string {
    return renderCustomAsiaOrderCustomerMessage(template, {
        productName: 'CCSTOYS EVA 02',
        customerPriceCad: '580',
        depositPercent: '20',
    }) ?? '';
}
