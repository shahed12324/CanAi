import 'package:flutter/material.dart';

import 'screens/login.dart';
import 'screens/shell.dart';
import 'session.dart';
import 'ui.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const CanaiApp());
}

class CanaiApp extends StatefulWidget {
  const CanaiApp({super.key});
  @override
  State<CanaiApp> createState() => _CanaiAppState();
}

class _CanaiAppState extends State<CanaiApp> {
  final session = Session();

  @override
  void initState() {
    super.initState();
    session.start();
  }

  ThemeData _theme(Brightness b) => ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF2563EB), brightness: b),
      );

  @override
  Widget build(BuildContext context) {
    return SessionScope(
      session: session,
      child: MaterialApp(
        title: 'CANAI',
        theme: _theme(Brightness.light),
        darkTheme: _theme(Brightness.dark),
        home: const _Gate(),
      ),
    );
  }
}

/// Logged in -> the app; logged out -> login. Because it listens to the session, logging out anywhere (or a 401) lands here.
class _Gate extends StatelessWidget {
  const _Gate();
  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    switch (s.status) {
      case SessionStatus.loading:
        return const Scaffold(body: Loading());
      case SessionStatus.out:
        return const LoginScreen();
      case SessionStatus.signedIn:
        return const Shell();
    }
  }
}
