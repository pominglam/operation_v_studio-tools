export function splitBulkSearchTerms(input: string, maxLines = 60): string[] {
    const rawLines = input
        .split(/\r?\n/g)
        .map((l) => l.trim())
        .filter((l) => l !== '');

    // Keep order but dedupe (case-insensitive) to avoid duplicate requests.
    const seen = new Set<string>();
    const out: string[] = [];
    for (const line of rawLines) {
        const key = line.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        out.push(line);
        if (out.length >= maxLines) break;
    }
    return out;
}

export function mergeById<T extends { id: string }>(lists: T[][]): T[] {
    const merged = new Map<string, T>();
    for (const list of lists) {
        for (const item of list) {
            // Preserve first-seen ordering for stable UX.
            if (!merged.has(item.id)) merged.set(item.id, item);
        }
    }
    return Array.from(merged.values());
}

