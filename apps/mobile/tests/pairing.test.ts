import { describe, expect, it } from 'vitest';
import { parsePairing } from '@/lib/pairing';

const code = 'a1'.repeat(20);
describe('parsePairing', () => {
  it('reads a valid QR', () => {
    expect(parsePairing(`canai://pair?server=${encodeURIComponent('https://app.example.com')}&code=${code}`)).toEqual({ server: 'https://app.example.com', code });
  });
  it('rejects wrong scheme, bad code, insecure or credentialed server', () => {
    expect(parsePairing(`https://evil.com/?code=${code}`)).toBeNull();
    expect(parsePairing(`canai://pair?server=https%3A%2F%2Fa.com&code=short`)).toBeNull();
    expect(parsePairing(`canai://pair?server=http%3A%2F%2Fa.com&code=${code}`)).toBeNull();
    expect(parsePairing(`canai://pair?server=${encodeURIComponent('https://u:p@a.com')}&code=${code}`)).toBeNull();
    expect(parsePairing('')).toBeNull();
  });
});
