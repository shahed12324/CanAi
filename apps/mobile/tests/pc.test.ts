import { describe, expect, it } from 'vitest';
import { endpoints } from '@/api/endpoints';
import { ApiClient, type FetchLike } from '@/api/client';
import { stepIsHigh, stepsNeedingApproval } from '@/lib/pc';

const plan = (over = {}) => ({ id: 'p', device_id: 'd', device: 'PC', goal: 'g', status: 'approved', reason: null, result: null, created_at: '', expires_at: '',
  steps: [{ id: 's1', kind: 'move_file', target: '/a -> /b' }, { id: 's2', kind: 'write_file', target: '/a' }, { id: 's3', kind: 'screenshot', target: 'x'.repeat(300) }], needs: ['s1', 's3', 'zzz'], ...over });

describe('plan step approval helpers', () => {
  it('lists only known steps that need approval and marks high-risk ones', () => {
    const r = stepsNeedingApproval(plan());
    expect(r.map((x) => x.id)).toEqual(['s1', 's3']);
    expect(r[0]).toMatchObject({ label: 'Move file', high: true });
    expect(r[1]!.target.length).toBeLessThanOrEqual(201);
  });
  it('shows nothing for finished or cancelled plans', () => {
    for (const st of ['done', 'failed', 'cancelled', 'expired']) expect(stepsNeedingApproval(plan({ status: st }))).toEqual([]);
  });
  it('knows the high kinds the server knows', () => {
    for (const k of ['delete_file', 'run_command', 'input_control', 'move_file', 'trash_file', 'screenshot', 'power']) expect(stepIsHigh(k)).toBe(true);
    for (const k of ['read_file', 'write_file', 'copy_file', 'browser_extract', 'create_docx']) expect(stepIsHigh(k)).toBe(false);
  });
});

describe('plan + mode endpoints', () => {
  it('call the right routes', async () => {
    const calls: string[] = [];
    const f: FetchLike = async (url, init) => { calls.push(`${init.method} ${url} ${init.body ?? ''}`); return { status: 200, text: async () => JSON.stringify({ data: {} }) }; };
    const c = new ApiClient({ baseUrl: 'https://a.example.com', fetch: f, getToken: () => 't', getTenant: () => 'T'.repeat(26), onUnauthorized: () => {}, appVersion: '1' });
    const api = endpoints(c);
    await api.pcPlans(); await api.approvePlanStep('P'.repeat(26), 's1'); await api.userMode(); await api.setUserMode('companion');
    expect(calls[0]).toContain('GET https://a.example.com/api/v1/mobile/pc/plans');
    expect(calls[1]).toContain(`POST https://a.example.com/api/v1/mobile/pc/plans/${'P'.repeat(26)}/step {"step":"s1"}`);
    expect(calls[2]).toContain('GET https://a.example.com/api/v1/mobile/mode');
    expect(calls[3]).toContain('POST https://a.example.com/api/v1/mobile/mode {"mode":"companion"}');
  });
});
