import 'package:flutter/material.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';
import 'computer.dart';
import 'health.dart';
import 'notifications.dart';
import 'security.dart';
import 'usage.dart';
import 'workspaces.dart';

class MoreTab extends StatelessWidget {
  const MoreTab({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    final ws = s.workspaces.cast<Map<String, dynamic>?>().firstWhere((w) => w!['id'] == s.tenant, orElse: () => null);
    final minV = '${s.boot?['min_app_version'] ?? '0.0.0'}';
    final outdated = s.boot != null && !versionAtLeast(appVersion, minV);

    void open(Widget w) => Navigator.push(context, MaterialPageRoute<void>(builder: (_) => w));
    Widget item(String title, Widget to, {String? sub, String? badge}) => Panel(
          onTap: () => open(to),
          children: [Line(children: [H(title), if (badge != null) Badge2(badge, tone: Tone.primary)]), if (sub != null) P(sub, sub: true, small: true)],
        );

    return FutureBuilder<List<Map<String, dynamic>>>(
      future: s.api.notifications(unreadOnly: true).catchError((_) => <Map<String, dynamic>>[]),
      builder: (context, snap) {
        final unread = snap.data?.length ?? 0;
        return ListPage(children: [
          if (outdated) Panel(tone: Tone.warn, children: [const H('Update the app'), P('A newer app version is needed for this server ($minV). Some features may not work.', sub: true, small: true)]),
          Panel(children: [H('${s.user['name'] ?? ''}'), P('${s.user['email'] ?? ''}', sub: true, small: true), P('Workspace: ${ws?['name'] ?? '—'}', sub: true, small: true)]),
          item('Notifications', const NotificationsScreen(), badge: unread > 0 ? '$unread' : null),
          item('Usage and credits', const UsageScreen(), sub: 'How many credits were spent'),
          item('My Computer', const ComputerScreen(), sub: 'Approve your computer’s pending jobs'),
          item('Security and PIN', const SecurityScreen(), sub: 'Approval PIN'),
          if (s.workspaces.length > 1) item('Switch workspace', const WorkspacesScreen()),
          if (s.canDo('health')) item('System health', const HealthScreen(), sub: 'AI engine, email accounts'),
          P('Server: ${s.baseUrl} · version $appVersion', sub: true, small: true),
          Btn('Log out', kind: 'danger', onPressed: () async {
            if (await confirmDialog(context, 'Log out', 'Log out of this phone?', destructive: true)) await s.signOut();
          }),
        ]);
      },
    );
  }
}
