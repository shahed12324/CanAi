import 'package:flutter/material.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';

class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key});
  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  final password = TextEditingController();
  final pin = TextEditingController();
  final again = TextEditingController();
  Object? err;
  String ok = '';
  bool busy = false;

  String? get local => pinProblem(pin.text) ?? (pin.text != again.text ? 'The two PINs do not match.' : null);

  Future<void> save() async {
    final s = SessionScope.of(context);
    setState(() {
      err = null;
      ok = '';
      busy = true;
    });
    try {
      await s.api.setPin(pin.text, password.text);
      setState(() {
        ok = 'PIN has been set.';
        password.clear();
        pin.clear();
        again.clear();
      });
    } catch (e) {
      setState(() => err = e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final problem = local;
    return Scaffold(
      appBar: AppBar(title: const Text('Security and PIN')),
      body: ListPage(children: [
        const Panel(children: [
          H('Approval PIN'),
          P('Approving emails and turning on the emergency stop need a 6-digit PIN. Changing the PIN needs your account password.', sub: true, small: true),
        ]),
        Notice(error: err),
        Notice(tone: Tone.ok, text: ok),
        TextField(controller: password, obscureText: true, onChanged: (_) => setState(() {}), decoration: const InputDecoration(labelText: 'Account password', border: OutlineInputBorder())),
        TextField(controller: pin, obscureText: true, keyboardType: TextInputType.number, maxLength: 6, onChanged: (_) => setState(() {}), decoration: const InputDecoration(labelText: 'New PIN (6 digits)', counterText: '', border: OutlineInputBorder())),
        TextField(controller: again, obscureText: true, keyboardType: TextInputType.number, maxLength: 6, onChanged: (_) => setState(() {}), decoration: const InputDecoration(labelText: 'PIN again', counterText: '', border: OutlineInputBorder())),
        if (pin.text.length == 6 && problem != null) P(problem, color: const Color(0xFFD97706), small: true),
        Btn('Save PIN', busy: busy, onPressed: password.text.isEmpty || problem != null ? null : save),
      ]),
    );
  }
}
