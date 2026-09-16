export function shopifyInvoiceLineTitle(
    kind: 'deposit' | 'balance',
    productName: string | null | undefined,
): string {
    const stripped = (productName ?? '')
        .trim()
        .replace(/^(Deposit|Balance)\s*[—–-]\s*/i, '')
        .trim();

    if (stripped === '') {
        return kind === 'deposit' ? 'Deposit — Special order' : 'Balance — Special order';
    }

    return kind === 'deposit' ? `Deposit — ${stripped}` : `Balance — ${stripped}`;
}
