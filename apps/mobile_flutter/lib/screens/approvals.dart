import 'package:flutter/material.dart';

import '../api_client.dart';
import '../helpers.dart';
import '../session.dart';
import '../stepup.dart';
import '../ui.dart';

class ApprovalsTab extends StatelessWidget {
  const ApprovalsTab({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    final canApprove = s.canDo('approve');
    return LoadView<List<Map<String, dynamic>>>(
      load: s.api.approvals,
      builder: (context, list, error, reload, setData) {
        Future<void> decide(Map<String, dynamic> a, String decision) async {
          try {
            await runSensitive(context, s, () => s.api.decide('${a['kind']}', '${a['ref']}', decision));
            setData(list.where((x) => x['ref'] != a['ref']).toList());
          } catch (e) {
            if (e is ApiError && e.code == 'cancelled') return;
            if (context.mounted) alert(context, 'Failed', e is ApiError ? (approvalWhy[e.code] ?? e.message) : 'Try again.');
            if (e is ApiError && e.code == 'not_pending') await reload();
          }
        }

        return ListPage(onRefresh: reload, children: [
          Notice(error: error),
          if (!canApprove) const Notice(tone: Tone.warn, text: 'You can view, but you do not have permission to approve.'),
          const P('No email is sent unless you approve it here. Approving needs your PIN.', sub: true, small: true),
          if (list.isEmpty) const Empty('Nothing is waiting right now. ✅'),
          for (final a in list)
            Panel(children: [
              H('${a['title']}'),
              P('${a['summary'] ?? ''}', sub: true),
              P(when(a['created_at'] as String?), sub: true, small: true),
              if (canApprove)
                Row(children: [
                  Expanded(child: Btn('Approve', onPressed: () => decide(a, 'approve'))),
                  const SizedBox(width: 10),
                  Expanded(child: Btn('Reject', kind: 'ghost', onPressed: () => decide(a, 'reject'))),
                ]),
            ]),
          if (list.isNotEmpty) P('Total ${list.length} waiting', sub: true, small: true),
        ]);
      },
    );
  }
}
