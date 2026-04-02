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

export function buildNewtypeSearchUrl(query: string): string | null {
  const q = query.trim();
  if (!q) return null;
  return `https://newtype.us/search?q=${encodeURIComponent(q)}`;
}

export function buildGundamHangarSearchUrl(query: string): string | null {
  const q = query.trim();
  if (!q) return null;
  return `https://server.gundamhangar.com/api/products?search=${encodeURIComponent(q)}&page=1&outofstock=&limit=10`;
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

  if (content.source === 'newtype') {
    return buildNewtypeSearchUrl(opts.query);
  }

  if (content.source === 'gundamhangar') {
    return buildGundamHangarSearchUrl(opts.query);
  }

  return null;
}


