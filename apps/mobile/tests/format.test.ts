import { describe, expect, it } from 'vitest';
import { bn, credits, versionAtLeast, when } from '@/lib/format';
import { pinProblem } from '@/lib/pin';

describe('format', () => {
  it('keeps ordinary digits', () => { expect(bn('2026/11')).toBe('2026/11'); });
  it('formats credits without float rounding', () => {
    expect(credits('1234567.50')).toBe('1,234,567.5');
    expect(credits('0.1000')).toBe('0.1');
    expect(credits('12.00')).toBe('12');
    expect(credits('-1500')).toBe('-1,500');
    expect(credits(null)).toBe('0');
  });
  it('says how long ago', () => {
    const now = new Date('2026-11-03T12:00:00Z');
    expect(when('2026-11-03T11:59:40Z', now)).toBe('just now');
    expect(when('2026-11-03T11:30:00Z', now)).toBe('30 min ago');
    expect(when('2026-11-03T09:00:00Z', now)).toBe('3 h ago');
    expect(when('2026-10-01 18:30:00+00', now)).toBe('02/10/2026');            // Dhaka is UTC+6, so 18:30Z is already the 2nd
    expect(when('garbage', now)).toBe('');
  });
  it('compares versions', () => {
    expect(versionAtLeast('1.2.0', '1.0.0')).toBe(true);
    expect(versionAtLeast('1.0.0', '1.0.1')).toBe(false);
    expect(versionAtLeast('2.0', '1.9.9')).toBe(true);
  });
});

describe('pin rule matches the server', () => {
  it('accepts and rejects the same values as PinPolicy.php', () => {
    for (const ok of ['482915', '907314']) expect(pinProblem(ok), ok).toBeNull();
    for (const bad of ['12345', '1234567', 'abcdef', '', '111111', '000000', '123456', '654321', '234567', '890123', '321098', '121212', '112233']) expect(pinProblem(bad), bad).not.toBeNull();
  });
});
