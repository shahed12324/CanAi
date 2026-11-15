import type { PcAction } from '@/api/types';

export const KIND: Record<string, string> = {
  read_file: 'Read file', list_dir: 'Folder listing', write_file: 'Write file', open_app: 'Open app', browser_navigate: 'Open a site in the browser',
  system_setting: 'System setting', delete_file: 'Delete file', run_command: 'Run command', input_control: 'Mouse/keyboard control', power: 'Power (shutdown/restart)',
  copy_file: 'Copy file', move_file: 'Move file', make_dir: 'Create folder', trash_file: 'Move file to trash', create_xlsx: 'Create Excel file', create_docx: 'Create Word file',
  screenshot: 'Take a screenshot', browser_extract: 'Read a web page', verify_file: 'Check a file',
};
export const RISK: Record<PcAction['risk'], string> = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Very high' };

/** Server answers for the phone decision route -> what to tell the person. */
export const WHY: Record<string, string> = {
  not_pending: 'This job is no longer waiting.',
  expired: 'Expired (10 minutes) — request again from the computer.',
  emergency_stop: 'Emergency stop is on — the job was stopped.',
  not_found: 'The job was not found.',
  pin_locked: 'Too many wrong PINs. Try again in 15 minutes.',
  no_pin: 'First go to "More → Security" and set a PIN.',
};

/** Only the facts the person needs to decide, one per line; long text is cut so a screen never shows a wall of text. Never throws on odd params. */
export function summary(a: Pick<PcAction, 'kind' | 'params'>): string[] {
  const p = a.params ?? {};
  const str = (k: string) => (typeof p[k] === 'string' ? (p[k] as string) : null);
  const cut = (t: string, n = 200) => (t.length > n ? `${t.slice(0, n)}…` : t);
  const lines: string[] = [];
  for (const [label, key] of [['Path', 'path'], ['App', 'app'], ['Address', 'url'], ['Command', 'command'], ['Setting', 'setting'], ['Job', 'action']] as const) {
    const v = str(key);
    if (v) lines.push(`${label}: ${cut(v)}`);
  }
  if (Array.isArray(p.args) && p.args.every((x) => typeof x === 'string') && p.args.length > 0) lines.push(`Arguments: ${cut((p.args as string[]).join(' '))}`);
  const content = str('content');
  if (content) lines.push(`Content size: ${content.length} characters`);
  return lines;
}

export const isHighRisk = (a: Pick<PcAction, 'risk'>) => a.risk === 'high' || a.risk === 'critical';

/** Minutes left (rounded up), never negative. */
export function minutesLeft(expiresAt: string, now: Date = new Date()): number {
  const ms = Date.parse(expiresAt) - now.getTime();
  return Number.isNaN(ms) ? 0 : Math.max(0, Math.ceil(ms / 60000));
}

/** What the phone may hand to its computer (same list as the server's MOBILE_KINDS). */
export const HANDOFF: { kind: string; label: string; hint: string }[] = [
  { kind: 'read_file', label: 'Read a file', hint: 'Full path of the file, e.g. C:\\Users\\me\\Documents\\a.txt' },
  { kind: 'list_dir', label: 'View a folder', hint: 'Full path of the folder' },
  { kind: 'open_app', label: 'Open an app', hint: 'App name, e.g. Notepad' },
  { kind: 'browser_navigate', label: 'Open a site', hint: 'https:// address' },
];

/** Same checks as the server (it checks again): non-empty, at most 520 characters, no control characters. Returns the cleaned text or null. */
export function cleanTarget(raw: string): string | null {
  const t = raw.trim();
  // eslint-disable-next-line no-control-regex
  return t === '' || t.length > 520 || /[\u0000-\u001F\u007F]/.test(t) ? null : t;
}

export const REFUSED = 'This is not allowed by your computer’s rules (set the allowed folders/apps/sites on the web, under "My Computer" page), or emergency stop is on.';

/** Kinds the server rates high / critical: a plan step of these kinds can only be approved on the phone. Mirrors pc_kind_risk(). */
const HIGH_KINDS = new Set(['delete_file', 'run_command', 'input_control', 'move_file', 'trash_file', 'screenshot', 'power']);
export const stepIsHigh = (kind: string) => HIGH_KINDS.has(kind);

/** The steps of a plan that are waiting for THIS person, with a plain label.  Unknown step ids in `needs` are ignored. */
export function stepsNeedingApproval(plan: Pick<import('@/api/types').PcPlan, 'steps' | 'needs' | 'status'>) {
  if (plan.status !== 'approved' && plan.status !== 'pending') return [];
  return plan.needs.flatMap((id) => {
    const s = plan.steps.find((x) => x.id === id);
    return s ? [{ id, label: KIND[s.kind] ?? s.kind, target: s.target.length > 200 ? `${s.target.slice(0, 200)}…` : s.target, high: stepIsHigh(s.kind) }] : [];
  });
}

export const MODE_TEXT = {
  companion: 'Companion: chat and voice only. The computer is never asked to do anything.',
  agent: 'Agent: computer jobs follow your device rules and need your approval.',
} as const;
