import 'package:flutter/material.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';

class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: LoadView<List<Map<String, dynamic>>>(
        load: () async {
          final list = await s.api.notifications();
          if (list.any((x) => x['read'] != true)) s.api.markAllRead().catchError((_) {}); // opening the list reads everything
          return list;
        },
        builder: (context, list, error, reload, _) => ListPage(onRefresh: reload, children: [
          Notice(error: error),
          if (list.isEmpty) const Empty('No notifications.'),
          for (final x in list)
            Panel(tone: x['kind'] == 'emergency' ? Tone.danger : Tone.none, children: [
              Line(children: [H('${x['title']}'), if (x['read'] != true) const Badge2('New', tone: Tone.primary)]),
              if (('${x['body'] ?? ''}').isNotEmpty) P('${x['body']}', sub: true),
              P(when(x['created_at'] as String?), sub: true, small: true),
            ]),
        ]),
      ),
    );
  }
}
