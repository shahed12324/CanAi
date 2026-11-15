import 'package:flutter/material.dart';

import '../api_client.dart';
import '../session.dart';
import '../ui.dart';
import 'scan.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final server = TextEditingController();
  final email = TextEditingController();
  final password = TextEditingController();
  final code = TextEditingController();
  bool needCode = false;
  bool busy = false;
  Object? err;
  bool inited = false;

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    if (!inited) {
      server.text = s.baseUrl;
      inited = true;
    }
    final hasBase = s.baseUrl.isNotEmpty;

    Future<void> submit() async {
      setState(() {
        err = null;
        busy = true;
      });
      try {
        await s.signIn(server.text.isEmpty ? s.baseUrl : server.text, email.text, password.text, code: needCode ? code.text : null);
      } catch (e) {
        if (e is ApiError && e.code == 'two_factor_required') needCode = true;
        if (mounted) setState(() => err = e);
      } finally {
        if (mounted) setState(() => busy = false);
      }
    }

    return Scaffold(
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(20), children: [
          const SizedBox(height: 24),
          Center(child: Text('CANAI', style: TextStyle(fontSize: 34, fontWeight: FontWeight.w800, color: Theme.of(context).colorScheme.primary))),
          const SizedBox(height: 6),
          const Center(child: P('Your AI assistant, now in your pocket', sub: true)),
          const SizedBox(height: 22),
          Btn('Scan QR code from the website', onPressed: () => Navigator.push(context, MaterialPageRoute<void>(builder: (_) => const ScanScreen()))),
          const SizedBox(height: 8),
          const Center(child: P('or log in with email', sub: true, small: true)),
          const SizedBox(height: 12),
          Notice(error: err),
          if (err != null) const SizedBox(height: 10),
          if (!hasBase) ...[
            TextField(controller: server, keyboardType: TextInputType.url, autocorrect: false, decoration: const InputDecoration(labelText: 'Server address', hintText: 'https://your-domain.com', border: OutlineInputBorder())),
            const SizedBox(height: 10),
          ],
          TextField(controller: email, keyboardType: TextInputType.emailAddress, autocorrect: false, decoration: const InputDecoration(labelText: 'Email', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: password, obscureText: true, decoration: const InputDecoration(labelText: 'Password', border: OutlineInputBorder())),
          if (needCode) ...[
            const SizedBox(height: 10),
            TextField(controller: code, keyboardType: TextInputType.number, maxLength: 10, decoration: const InputDecoration(labelText: 'Two-step code (6 digits from your authenticator app)', border: OutlineInputBorder())),
          ],
          const SizedBox(height: 12),
          Btn('Log in', busy: busy, onPressed: submit),
          if (hasBase) TextButton(onPressed: () => setState(() => s.baseUrl = ''), child: Text('Server: ${s.baseUrl.replaceFirst('https://', '')} (change)')),
        ]),
      ),
    );
  }
}
