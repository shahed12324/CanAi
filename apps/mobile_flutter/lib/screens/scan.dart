import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';

/// Scans the QR from the website's "Mobile app" page; the one-time code in it logs this phone in.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key});
  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  bool busy = false;
  Object? err;

  Future<void> onCode(String data) async {
    if (busy) return;
    final s = SessionScope.of(context);
    final p = parsePairing(data);
    if (p == null) {
      setState(() => err = 'This is not a CANAI QR code. Use the one on the "Mobile app" page of the website.');
      return;
    }
    setState(() {
      busy = true;
      err = null;
    });
    try {
      await s.pairWith(p.server, p.code);
      if (mounted) Navigator.of(context).popUntil((r) => r.isFirst); // the gate now shows the app
    } catch (e) {
      if (mounted) setState(() => err = e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scan QR code')),
      body: Stack(children: [
        MobileScanner(
          onDetect: (capture) {
            for (final b in capture.barcodes) {
              final v = b.rawValue;
              if (v != null) {
                onCode(v);
                break;
              }
            }
          },
          errorBuilder: (context, error, child) => const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: Text('The camera is needed only to scan the QR code from the website. Allow camera access in Settings and try again.', textAlign: TextAlign.center)),
          ),
        ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 0,
          child: Container(
            color: Theme.of(context).scaffoldBackgroundColor,
            padding: const EdgeInsets.all(16),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Notice(text: err is String ? err as String : null, error: err is String ? null : err),
              const SizedBox(height: 8),
              P(busy ? 'Connecting…' : 'Point the camera at the QR code on the website (Mobile app page).', sub: true),
            ]),
          ),
        ),
      ]),
    );
  }
}
