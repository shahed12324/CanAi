import 'package:flutter/material.dart';

import 'api_client.dart';
import 'session.dart';

/// Runs a sensitive action. If the server answers "step_up_required" the user is asked for the PIN, the PIN is
/// verified by the SERVER, and the action is retried once. The phone never decides on its own that a PIN was right.
Future<T> runSensitive<T>(BuildContext context, Session s, Future<T> Function() action) async {
  try {
    return await action();
  } on ApiError catch (e) {
    if (e.code != 'step_up_required') rethrow;
  }
  ApiError? previous;
  const maxTries = 5;
  for (var i = 0; i < maxTries; i++) {
    final pin = await askPin(context, previous);
    if (pin == null) throw ApiError(0, 'cancelled', 'Cancelled.');
    try {
      await s.api.stepUp(pin);
      break;
    } on ApiError catch (e) {
      if (e.code == 'bad_pin') {
        previous = e;
        if (i == maxTries - 1) rethrow;
        continue;
      }
      rethrow; // locked, no_pin, network...: stop asking
    }
  }
  return action();
}

Future<String?> askPin(BuildContext context, ApiError? previous) {
  final ctl = TextEditingController();
  return showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (c) => AlertDialog(
      title: const Text('Enter your PIN'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        if (previous != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(previous.message, style: const TextStyle(color: Color(0xFFDC2626)))),
        TextField(controller: ctl, autofocus: true, obscureText: true, keyboardType: TextInputType.number, maxLength: 6, decoration: const InputDecoration(labelText: '6-digit PIN')),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c), child: const Text('Cancel')),
        TextButton(onPressed: () => Navigator.pop(c, ctl.text), child: const Text('OK')),
      ],
    ),
  );
}
