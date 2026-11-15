import 'package:flutter/material.dart';

import '../api_client.dart';
import '../helpers.dart';
import '../session.dart';
import '../stepup.dart';
import '../ui.dart';

/// The person's own computer asking for permission. High-risk actions can be approved ONLY here (PIN required).
class ComputerScreen extends StatefulWidget {
  const ComputerScreen({super.key});
  @override
  State<ComputerScreen> createState() => _ComputerScreenState();
}

class _ComputerScreenState extends State<ComputerScreen> {
  String kind = pcHandoff.first.$1;
  String? deviceId;
  final target = TextEditingController();
  bool busy = false;
  int round = 0;

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('My Computer')),
      body: LoadView<(List<Map<String, dynamic>>, List<Map<String, dynamic>>)>(
        key: ValueKey(round),
        load: () async => (await s.api.pcActions(), await s.api.pcDevices()),
        builder: (context, data, error, reload, setData) {
          final rows = data.$1;
          final devs = data.$2;
          final chosen = deviceId ?? (devs.length == 1 ? '${devs.first['id']}' : null);
          final hint = pcHandoff.firstWhere((h) => h.$1 == kind).$3;

          Future<void> send() async {
            final t = cleanTarget(target.text);
            if (chosen == null || t == null) {
              alert(context, 'Failed', chosen != null ? 'The text is empty or too long.' : 'Which computer — pick one.');
              return;
            }
            setState(() => busy = true);
            try {
              await s.api.pcRequest(chosen, kind, t);
              target.clear();
              setState(() => round++);
            } catch (e) {
              if (context.mounted) alert(context, 'Failed', e is ApiError ? (e.code == 'refused' ? pcRefused : e.message) : 'Try again.');
            } finally {
              if (mounted) setState(() => busy = false);
            }
          }

          Future<void> decide(Map<String, dynamic> a, String decision) async {
            try {
              await runSensitive(context, s, () => s.api.decidePc('${a['id']}', decision));
              setData((rows.where((x) => x['id'] != a['id']).toList(), devs));
            } catch (e) {
              if (e is ApiError && e.code == 'cancelled') return;
              if (context.mounted) alert(context, 'Failed', e is ApiError ? (pcWhy[e.code] ?? e.message) : 'Try again.');
              if (e is ApiError && const ['not_pending', 'expired', 'emergency_stop', 'not_found'].contains(e.code)) setState(() => round++);
            }
          }

          Future<void> confirm(Map<String, dynamic> a) async {
            final ok = await confirmDialog(context, '${pcKind['${a['kind']}'] ?? a['kind']} — Approve?', '${a['device']}\n${pcSummary(a).join('\n')}${isHighRisk(a) ? '\n\n⚠ High-risk job. Continue only if you are sure.' : ''}', yes: 'Approve');
            if (ok) await decide(a, 'approve');
          }

          return ListPage(onRefresh: reload, children: [
            Notice(error: error),
            const P('What your computer wants to do arrives here. It expires in 10 minutes. Approving needs your PIN.', sub: true, small: true),
            Panel(children: [
              const H('Send to computer'),
              if (devs.isEmpty)
                const P('No computer is paired — on the web, open the "My Computer" page and pair one.', sub: true, small: true)
              else ...[
                if (devs.length > 1)
                  Wrap(spacing: 8, children: [for (final d in devs) ChoiceChip(label: Text('${d['name']}'), selected: chosen == '${d['id']}', onSelected: (_) => setState(() => deviceId = '${d['id']}'))])
                else
                  P('${devs.first['name']}', sub: true, small: true),
                Wrap(spacing: 8, children: [for (final h in pcHandoff) ChoiceChip(label: Text(h.$2), selected: kind == h.$1, onSelected: (_) => setState(() => kind = h.$1))]),
                TextField(controller: target, maxLength: 520, autocorrect: false, decoration: InputDecoration(labelText: hint, counterText: '', border: const OutlineInputBorder())),
                Btn('Send', busy: busy, onPressed: send),
                const P('Requests will appear in this list; if they follow your computer’s rules you approve them right here.', sub: true, small: true),
              ],
            ]),
            if (rows.isEmpty) const Empty('No jobs are waiting right now. ✅'),
            for (final a in rows)
              Panel(tone: isHighRisk(a) ? Tone.warn : Tone.none, children: [
                Line(children: [H('${pcKind['${a['kind']}'] ?? a['kind']}'), Badge2('Risk: ${pcRisk['${a['risk']}'] ?? a['risk']}', tone: isHighRisk(a) ? Tone.danger : Tone.sub)]),
                P('${a['device']}', sub: true, small: true),
                for (final l in pcSummary(a)) P(l, small: true),
                P('${minutesLeft('${a['expires_at']}')} minutes left', sub: true, small: true),
                Row(children: [
                  Expanded(child: Btn('Approve', onPressed: () => confirm(a))),
                  const SizedBox(width: 10),
                  Expanded(child: Btn('Reject', kind: 'ghost', onPressed: () => decide(a, 'reject'))),
                ]),
              ]),
          ]);
        },
      ),
    );
  }
}
