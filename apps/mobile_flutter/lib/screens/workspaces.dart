import 'package:flutter/material.dart';

import '../session.dart';
import '../ui.dart';

class WorkspacesScreen extends StatelessWidget {
  const WorkspacesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Workspace')),
      body: ListPage(children: [
        for (final w in s.workspaces)
          Panel(
            tone: w['id'] == s.tenant ? Tone.ok : Tone.none,
            onTap: () async {
              try {
                await s.switchTenant('${w['id']}');
                if (context.mounted) Navigator.pop(context);
              } catch (_) {
                if (context.mounted) alert(context, 'Failed', 'The workspace could not be switched.');
              }
            },
            children: [
              Line(children: [H('${w['name']}'), if (w['id'] == s.tenant) const Badge2('Current', tone: Tone.ok)]),
              P(w['type'] == 'personal' ? 'Personal' : 'Organization', sub: true, small: true),
            ],
          ),
      ]),
    );
  }
}
