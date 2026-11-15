/** Numbers are shown with ordinary digits (0-9). Kept as a function so every call site stays the same. */
export function bn(n: string | number): string {
  return String(n);
}

/** "1234.50" -> "1,234.5"; grouping is the western 3-digit style, which is what the web app shows. */
export function credits(v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === '') return bn(0);
  const s = String(v);
  const [i = '0', f] = s.split('.');
  const neg = i.startsWith('-');
  const digits = i.replace('-', '');
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const frac = f && /[1-9]/.test(f) ? '.' + f.replace(/0+$/, '') : '';
  return bn((neg ? '-' : '') + grouped + frac);
}

/** Server times are UTC/ISO or "YYYY-MM-DD HH:MM:SS+00". Shows Dhaka time. */
export function when(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return '';
  const d = new Date(iso.replace(' ', 'T').replace(/([+-]\d\d)$/, '$1:00'));
  if (Number.isNaN(d.getTime())) return '';
  const diff = Math.round((now.getTime() - d.getTime()) / 60000);
  if (diff < 1) return 'just now';
  if (diff < 60) return `${bn(diff)} min ago`;
  if (diff < 1440) return `${bn(Math.floor(diff / 60))} h ago`;
  const dhaka = new Date(d.getTime() + 6 * 3600 * 1000);
  return bn(`${String(dhaka.getUTCDate()).padStart(2, '0')}/${String(dhaka.getUTCMonth() + 1).padStart(2, '0')}/${dhaka.getUTCFullYear()}`);
}

/** true if `have` is at least `need` (both "1.2.3"). */
export function versionAtLeast(have: string, need: string): boolean {
  const a = have.split('.').map((x) => parseInt(x, 10) || 0);
  const b = need.split('.').map((x) => parseInt(x, 10) || 0);
  for (let i = 0; i < 3; i++) {
    if ((a[i] ?? 0) !== (b[i] ?? 0)) return (a[i] ?? 0) > (b[i] ?? 0);
  }
  return true;
}
