export function decodeHtmlEntitiesDeep(input: string): string {
  // Decode up to two passes (handles double-encoded strings like "&amp;#39;" -> "&#39;" -> "'").
  const decodeOnce = (s: string): string => {
    const el = document.createElement('textarea');
    el.innerHTML = s;
    return el.value;
  };

  let cur = input ?? '';
  for (let i = 0; i < 2; i++) {
    const next = decodeOnce(cur);
    if (next === cur) break;
    cur = next;
  }
  return cur;
}


