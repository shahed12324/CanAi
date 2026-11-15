import 'package:flutter/material.dart';

import '../session.dart';
import '../ui.dart';

class HealthScreen extends StatelessWidget {
  const HealthScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('System health')),
      body: LoadView<Map<String, dynamic>>(
        load: s.api.health,
        builder: (context, d, error, reload, _) {
          final ok = d['ok'] == true;
          final em = (d['emergency'] as Map?)?.cast<String, dynamic>() ?? const {};
          final pending = (d['pending_approvals'] as num?)?.toInt() ?? 0;
          final failed = (d['failed_mails_24h'] as num?)?.toInt() ?? 0;
          final bad = ((d['email_accounts_with_errors'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();
          return ListPage(onRefresh: reload, children: [
            Notice(error: error),
            Panel(tone: ok ? Tone.ok : Tone.warn, children: [H(ok ? '✅ All good' : '⚠ Needs attention')]),
            Panel(children: [Line(children: [const H('AI engine'), Badge2(d['ai_engine'] == true ? 'On' : 'Not connected', tone: d['ai_engine'] == true ? Tone.ok : Tone.danger)])]),
            Panel(children: [Line(children: [const H('Emergency stop'), Badge2(em['active'] == true ? 'On (everything paused)' : 'Off', tone: em['active'] == true ? Tone.danger : Tone.ok)])]),
            Panel(children: [Line(children: [const H('Waiting for approval'), Badge2('$pending', tone: pending > 0 ? Tone.warn : Tone.ok)])]),
            Panel(children: [Line(children: [const H('Failed emails (24 h)'), Badge2('$failed', tone: failed > 0 ? Tone.danger : Tone.ok)])]),
            const H('Email account problems'),
            if (bad.isEmpty) const P('No problems.', sub: true),
            for (final a in bad) Panel(tone: Tone.danger, children: [H('${a['name']}'), P('${a['from']} · ${a['provider']}', sub: true, small: true), P('${a['error']}', color: const Color(0xFFDC2626))]),
          ]);
        },
      ),
    );
  }
}
