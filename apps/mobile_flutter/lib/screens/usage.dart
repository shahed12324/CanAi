import 'dart:math';

import 'package:flutter/material.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';

class UsageScreen extends StatelessWidget {
  const UsageScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Usage and credits')),
      body: LoadView<Map<String, dynamic>>(
        load: s.api.usage,
        builder: (context, d, error, reload, _) {
          final daily = ((d['daily'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();
          final mx = daily.fold<double>(1, (m, x) => max(m, double.tryParse('${x['credits']}') ?? 0));
          final primary = Theme.of(context).colorScheme.primary;
          return ListPage(onRefresh: reload, children: [
            Notice(error: error),
            Panel(children: [const P('Balance', sub: true, small: true), Big(credits(d['balance']))]),
            Panel(children: [
              const P('Spent this month', sub: true, small: true),
              Big(credits(d['spent_this_month'])),
              if (d['monthly_limit'] != null) P('Monthly limit: ${credits(d['monthly_limit'])}', sub: true, small: true),
            ]),
            const H('Last 30 days'),
            if (daily.isEmpty) const Empty('No spending yet.') else
              Panel(children: [
                for (final x in daily)
                  Row(children: [
                    SizedBox(width: 48, child: P('${x['day']}'.substring(5).replaceFirst('-', '/'), sub: true, small: true)),
                    Expanded(
                      child: LayoutBuilder(builder: (c, box) {
                        final w = max(3.0, (double.tryParse('${x['credits']}') ?? 0) / mx * 100) / 100 * box.maxWidth;
                        return Align(alignment: Alignment.centerLeft, child: Container(height: 10, width: w, decoration: BoxDecoration(color: primary, borderRadius: BorderRadius.circular(5))));
                      }),
                    ),
                    const SizedBox(width: 8),
                    P(credits(x['credits']), small: true),
                  ]),
              ]),
          ]);
        },
      ),
    );
  }
}
