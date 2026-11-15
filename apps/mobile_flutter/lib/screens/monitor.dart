import 'package:flutter/material.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';
import 'health.dart';

const _status = <String, (String, Tone)>{
  'active': ('Running', Tone.ok), 'paused': ('Paused', Tone.warn), 'draft': ('Draft', Tone.sub), 'done': ('Done', Tone.ok),
  'queued': ('Queued', Tone.sub), 'running': ('Running', Tone.ok), 'cancelled': ('Rejected', Tone.danger),
};

class MonitorTab extends StatelessWidget {
  const MonitorTab({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return LoadView<Map<String, dynamic>>(
      load: s.api.automations,
      builder: (context, d, error, reload, _) {
        final camps = ((d['campaigns'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();
        final jobs = ((d['research_jobs'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();
        (String, Tone) st(Object? k) => _status['$k'] ?? ('$k', Tone.sub);
        return ListPage(onRefresh: reload, children: [
          Notice(error: error),
          const P('What runs on its own in your workspace.', sub: true, small: true),
          if (s.canDo('health')) Btn('View system health', kind: 'ghost', onPressed: () => Navigator.push(context, MaterialPageRoute<void>(builder: (_) => const HealthScreen()))),
          const H('Email campaigns'),
          if (camps.isEmpty) const Empty('No campaigns.'),
          for (final c in camps)
            Panel(children: [
              Line(children: [H('${c['name']}'), Badge2(st(c['status']).$1, tone: st(c['status']).$2)]),
              P('Waiting for approval ${c['pending']} · in send queue ${c['queued']} · Sent ${c['sent']} · Replies ${c['replied']}${(c['failed'] as num? ?? 0) > 0 ? ' · Failed ${c['failed']}' : ''}', sub: true, small: true),
            ]),
          const H('Lead research'),
          if (jobs.isEmpty) const Empty('No research jobs.'),
          for (final j in jobs)
            Panel(children: [
              Line(children: [H('${j['name']}'), Badge2(st(j['status']).$1, tone: st(j['status']).$2)]),
              P('${j['total']} sites · ${when(j['created_at'] as String?)}', sub: true, small: true),
            ]),
        ]);
      },
    );
  }
}
