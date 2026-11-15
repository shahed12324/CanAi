import 'package:flutter/material.dart';

import '../session.dart';
import 'approvals.dart';
import 'chat_list.dart';
import 'home.dart';
import 'monitor.dart';
import 'more.dart';

class Shell extends StatefulWidget {
  const Shell({super.key});
  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  int index = 0;
  static const titles = ['Home', 'Chat', 'Approvals', 'Monitor', 'More'];

  void go(int i) => setState(() => index = i);

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    ValueKey<String> k(String n) => ValueKey('$n-${s.tenant}'); // a new workspace rebuilds every tab, so each reloads its data (keys must differ per tab)
    final pages = <Widget>[
      HomeTab(key: k('home'), goTo: go),
      ChatListTab(key: k('chat')),
      ApprovalsTab(key: k('approvals')),
      MonitorTab(key: k('monitor')),
      MoreTab(key: k('more')),
    ];
    return Scaffold(
      appBar: AppBar(title: Text(titles[index])),
      body: IndexedStack(index: index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: go,
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), label: 'Home'),
          NavigationDestination(icon: Icon(Icons.chat_bubble_outline), label: 'Chat'),
          NavigationDestination(icon: Icon(Icons.check_circle_outline), label: 'Approvals'),
          NavigationDestination(icon: Icon(Icons.monitor_heart_outlined), label: 'Monitor'),
          NavigationDestination(icon: Icon(Icons.menu), label: 'More'),
        ],
      ),
    );
  }
}
