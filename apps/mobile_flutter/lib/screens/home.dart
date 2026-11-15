import 'package:flutter/material.dart';

import '../api_client.dart';
import '../helpers.dart';
import '../session.dart';
import '../stepup.dart';
import '../ui.dart';
import 'chat.dart';
import 'notifications.dart';

class HomeTab extends StatelessWidget {
  const HomeTab({super.key, required this.goTo});
  final void Function(int) goTo;

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    final canStop = s.canDo('emergency');
    return LoadView<Map<String, dynamic>>(
      load: s.api.dashboard,
      builder: (context, x, error, reload, _) {
        final em = (x['emergency'] as Map?)?.cast<String, dynamic>() ?? const {};
        final stopped = em['active'] == true;
        final low = x['low_balance'] == true;
        final pending = (x['pending_approvals'] as num?)?.toInt() ?? 0;
        final chats = ((x['recent_chats'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();

        Future<void> stop() async {
          if (!await confirmDialog(context, 'Emergency stop', 'All automatic jobs in this workspace (sending emails, new approvals) will stop right now. You need your PIN to turn them back on.', yes: 'Stop', destructive: true)) return;
          try {
            await s.api.setEmergency('stop');
            await reload();
          } catch (e) {
            if (context.mounted) alert(context, 'Failed', e is ApiError ? e.message : 'Try again.');
          }
        }

        Future<void> resume() async {
          try {
            await runSensitive(context, s, () => s.api.setEmergency('resume'));
            await reload();
          } catch (e) {
            if (e is ApiError && e.code == 'cancelled') return;
            if (context.mounted) alert(context, 'Failed', e is ApiError ? e.message : 'Try again.');
          }
        }

        return ListPage(onRefresh: reload, children: [
          Notice(error: error),
          if (stopped)
            Panel(tone: Tone.danger, children: [
              const H('⛔ Emergency stop is on'),
              P('All automatic jobs are paused${em['since'] != null ? ' (${when(em['since'] as String?)})' : ''}.${em['reason'] != null ? ' Reason: ${em['reason']}' : ''}', sub: true),
              if (canStop) Btn('Turn back on (PIN needed)', onPressed: resume) else const P('Only the owner/admin may turn it back on.', sub: true, small: true),
            ]),
          Panel(tone: low ? Tone.warn : Tone.none, children: [
            const P('Credit balance', sub: true, small: true),
            Big(credits(x['balance']), color: low ? toneColor(context, Tone.warn) : null),
            Line(children: [P('Spent this month: ${credits(x['spent_this_month'])}', sub: true, small: true), if (low) const Badge2('Low balance', tone: Tone.warn)]),
          ]),
          Row(children: [
            Expanded(child: Panel(tone: pending > 0 ? Tone.warn : Tone.none, onTap: () => goTo(2), children: [const P('Waiting for approval', sub: true, small: true), Big('$pending')])),
            const SizedBox(width: 10),
            Expanded(
              child: Panel(
                onTap: () => Navigator.push(context, MaterialPageRoute<void>(builder: (_) => const NotificationsScreen())),
                children: [const P('New notifications', sub: true, small: true), Big('${x['unread'] ?? 0}')],
              ),
            ),
          ]),
          Panel(children: [const P('Leads (collected)', sub: true, small: true), Big('${x['leads'] ?? 0}')]),
          const H('Recent chats'),
          if (chats.isEmpty) const Empty('No chats yet.'),
          for (final c in chats)
            Panel(
              onTap: () => Navigator.push(context, MaterialPageRoute<void>(builder: (_) => ChatScreen(conversationId: c['id'] as String))),
              children: [P((c['title'] as String?) ?? 'New chat', bold: true), P(when(c['last_message_at'] as String?), sub: true, small: true)],
            ),
          if (canStop && !stopped) Btn('⛔ Emergency stop', kind: 'danger', onPressed: stop),
        ]);
      },
    );
  }
}
