import 'api_client.dart';

/// "1234.50" -> "1,234.5" (western 3-digit grouping, same as the web app).
String credits(Object? v) {
  if (v == null || v == '') return '0';
  final parts = '$v'.split('.');
  final i = parts[0];
  final neg = i.startsWith('-');
  final digits = i.replaceAll('-', '');
  final grouped = digits.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  final f = parts.length > 1 ? parts[1] : '';
  final frac = RegExp(r'[1-9]').hasMatch(f) ? '.${f.replaceAll(RegExp(r'0+$'), '')}' : '';
  return '${neg ? '-' : ''}$grouped$frac';
}

/// Server times are UTC/ISO or "YYYY-MM-DD HH:MM:SS+00". Shows relative time, or a Dhaka date when older than a day.
String when(String? iso, {DateTime? now}) {
  if (iso == null || iso.isEmpty) return '';
  var s = iso.replaceFirst(' ', 'T');
  s = s.replaceFirstMapped(RegExp(r'([+-]\d\d)$'), (m) => '${m[1]}:00');
  final d = DateTime.tryParse(s);
  if (d == null) return '';
  final diff = (now ?? DateTime.now()).difference(d).inMinutes;
  if (diff < 1) return 'just now';
  if (diff < 60) return '$diff min ago';
  if (diff < 1440) return '${diff ~/ 60} h ago';
  final dhaka = d.toUtc().add(const Duration(hours: 6));
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(dhaka.day)}/${two(dhaka.month)}/${dhaka.year}';
}

/// true if [have] is at least [need] (both "1.2.3").
bool versionAtLeast(String have, String need) {
  final a = have.split('.').map((x) => int.tryParse(x) ?? 0).toList();
  final b = need.split('.').map((x) => int.tryParse(x) ?? 0).toList();
  for (var i = 0; i < 3; i++) {
    final x = i < a.length ? a[i] : 0;
    final y = i < b.length ? b[i] : 0;
    if (x != y) return x > y;
  }
  return true;
}

const _weak = {'123456', '654321', '121212', '112233', '123123', '000000', '696969', '123321', '159753', '246810', '135790', '102030'};

/// Same rule as the server (PinPolicy.php) so the user is told immediately; the server still decides.
String? pinProblem(String pin) {
  if (!RegExp(r'^[0-9]{6}$').hasMatch(pin)) return 'The PIN must be exactly 6 digits (0-9).';
  final d = pin.split('').map(int.parse).toList();
  final same = d.toSet().length == 1;
  var up = true, down = true;
  for (var i = 1; i < 6; i++) {
    up = up && d[i] == (d[i - 1] + 1) % 10;
    down = down && d[i] == (d[i - 1] + 9) % 10;
  }
  if (same || up || down || _weak.contains(pin)) return 'This PIN is easy to guess. Choose another PIN.';
  return null;
}

/// What the website puts in the QR: canai://pair?server=<https url>&code=<40 hex>. Anything else is rejected, never guessed at.
({String server, String code})? parsePairing(String raw, {bool allowInsecureLocal = false}) {
  final t = raw.trim();
  final m = RegExp(r'^canai://pair\?(.+)$').firstMatch(t);
  if (m == null || t.length > 600) return null;
  final q = Uri.splitQueryString(m.group(1)!);
  final code = q['code'] ?? '';
  if (!RegExp(r'^[0-9a-f]{40}$').hasMatch(code)) return null;
  try {
    return (server: normalizeBaseUrl(q['server'] ?? '', allowInsecureLocal: allowInsecureLocal), code: code);
  } on ApiError {
    return null;
  }
}

// ---- My Computer (same lists as the server) ----
const pcKind = {
  'read_file': 'Read file', 'list_dir': 'Folder listing', 'write_file': 'Write file', 'open_app': 'Open app',
  'browser_navigate': 'Open a site in the browser', 'system_setting': 'System setting', 'delete_file': 'Delete file',
  'run_command': 'Run command', 'input_control': 'Mouse/keyboard control', 'power': 'Power (shutdown/restart)',
};
const pcRisk = {'low': 'Low', 'medium': 'Medium', 'high': 'High', 'critical': 'Very high'};
const pcWhy = {
  'not_pending': 'This job is no longer waiting.',
  'expired': 'Expired (10 minutes) — request again from the computer.',
  'emergency_stop': 'Emergency stop is on — the job was stopped.',
  'not_found': 'The job was not found.',
  'pin_locked': 'Too many wrong PINs. Try again in 15 minutes.',
  'no_pin': 'First go to "More → Security" and set a PIN.',
};
const approvalWhy = {
  'not_pending': 'This is no longer waiting — someone else has decided.',
  'suppressed': 'This address is on the block/unsubscribe list.',
  'emergency_stop': 'Emergency stop is on. Turn jobs back on first.',
  'pin_locked': 'Too many wrong PINs. Try again in 15 minutes.',
  'no_pin': 'First go to "More → Security" and set a PIN.',
};
const chatErr = {
  'insufficient_credits': 'Not enough credits. Buy credits on the website.',
  'spending_limit_reached': 'The monthly spending limit has been reached.',
  'no_model': 'No AI model is enabled on your plan.',
};
const pcHandoff = [
  ('read_file', 'Read a file', r'Full path of the file, e.g. C:\Users\me\Documents\a.txt'),
  ('list_dir', 'View a folder', 'Full path of the folder'),
  ('open_app', 'Open an app', 'App name, e.g. Notepad'),
  ('browser_navigate', 'Open a site', 'https:// address'),
];
const pcRefused =
    'This is not allowed by your computer’s rules (set the allowed folders/apps/sites on the web, under "My Computer" page), or emergency stop is on.';

bool isHighRisk(Map<String, dynamic> a) => a['risk'] == 'high' || a['risk'] == 'critical';

/// Minutes left (rounded up), never negative.
int minutesLeft(String expiresAt, {DateTime? now}) {
  final d = DateTime.tryParse(expiresAt.replaceFirst(' ', 'T'));
  if (d == null) return 0;
  final ms = d.difference(now ?? DateTime.now()).inMilliseconds;
  return ms <= 0 ? 0 : (ms / 60000).ceil();
}

/// Only the facts the person needs to decide, one per line; long text is cut. Never throws on odd params.
List<String> pcSummary(Map<String, dynamic> a) {
  final p = a['params'] is Map ? (a['params'] as Map).cast<String, dynamic>() : <String, dynamic>{};
  String? str(String k) => p[k] is String ? p[k] as String : null;
  String cut(String t, [int n = 200]) => t.length > n ? '${t.substring(0, n)}…' : t;
  final lines = <String>[];
  for (final e in const [('Path', 'path'), ('App', 'app'), ('Address', 'url'), ('Command', 'command'), ('Setting', 'setting'), ('Job', 'action')]) {
    final v = str(e.$2);
    if (v != null && v.isNotEmpty) lines.add('${e.$1}: ${cut(v)}');
  }
  final args = p['args'];
  if (args is List && args.isNotEmpty && args.every((x) => x is String)) lines.add('Arguments: ${cut(args.join(' '))}');
  final content = str('content');
  if (content != null && content.isNotEmpty) lines.add('Content size: ${content.length} characters');
  return lines;
}

/// Same checks as the server (it checks again): non-empty, at most 520 characters, no control characters.
String? cleanTarget(String raw) {
  final t = raw.trim();
  if (t.isEmpty || t.length > 520 || RegExp(r'[\u0000-\u001F\u007F]').hasMatch(t)) return null;
  return t;
}
