export type ContentSource = {
  source: string;
  source_url: string | null;
};

export function buildHljSearchUrl(query: string): string | null {
  const q = query.trim();
  if (!q) return null;
  return `https://www.hlj.com/search/?q=${encodeURIComponent(q)}`;
}

export function buildPlamodPdpUrl(sku: string): string | null {
  const s = sku.trim();
  if (!s) return null;
  return `https://plamod.com/retailer/products/${encodeURIComponent(s)}`;
}

export function descriptionSourceUrl(
  content: ContentSource | null,
  opts: { sku: string | null; query: string },
): string | null {
  if (!content) return null;
  const direct = (content.source_url ?? '').trim();
  if (direct) return direct;

  if (content.source === 'plamod' && opts.sku) {
    return buildPlamodPdpUrl(opts.sku);
  }

  return null;
}


