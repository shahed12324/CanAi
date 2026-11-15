import { normalizeBaseUrl } from '@/api/client';

/** What the website puts in the QR: canai://pair?server=<https url>&code=<40 hex>. Anything else is rejected, never guessed at. */
export function parsePairing(raw: string, allowInsecureLocal = false): { server: string; code: string } | null {
  const m = /^canai:\/\/pair\?(.+)$/.exec(raw.trim());
  if (!m || raw.length > 600) return null;
  const q = new URLSearchParams(m[1]);
  const code = q.get('code') ?? '';
  const serverRaw = q.get('server') ?? '';
  if (!/^[0-9a-f]{40}$/.test(code)) return null;
  try {
    return { server: normalizeBaseUrl(serverRaw, allowInsecureLocal), code };
  } catch {
    return null;
  }
}
