import 'package:flutter/material.dart';

import 'api_client.dart';

enum Tone { none, ok, warn, danger, sub, primary }

Color toneColor(BuildContext c, Tone t) {
  switch (t) {
    case Tone.ok:
      return const Color(0xFF16A34A);
    case Tone.warn:
      return const Color(0xFFD97706);
    case Tone.danger:
      return const Color(0xFFDC2626);
    case Tone.primary:
      return Theme.of(c).colorScheme.primary;
    case Tone.sub:
    case Tone.none:
      return Theme.of(c).colorScheme.onSurfaceVariant;
  }
}

/// A scrolling page with optional pull-to-refresh.
class ListPage extends StatelessWidget {
  const ListPage({super.key, required this.children, this.onRefresh});
  final List<Widget> children;
  final Future<void> Function()? onRefresh;

  @override
  Widget build(BuildContext context) {
    final list = ListView(padding: const EdgeInsets.all(14), children: [for (final c in children) Padding(padding: const EdgeInsets.only(bottom: 10), child: c)]);
    return onRefresh == null ? list : RefreshIndicator(onRefresh: onRefresh!, child: list);
  }
}

class Panel extends StatelessWidget {
  const Panel({super.key, required this.children, this.tone = Tone.none, this.onTap});
  final List<Widget> children;
  final Tone tone;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final border = tone == Tone.none ? Theme.of(context).dividerColor : toneColor(context, tone);
    final box = Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        for (var i = 0; i < children.length; i++) Padding(padding: EdgeInsets.only(top: i == 0 ? 0 : 6), child: children[i]),
      ]),
    );
    return onTap == null ? box : InkWell(borderRadius: BorderRadius.circular(14), onTap: onTap, child: box);
  }
}

class H extends StatelessWidget {
  const H(this.text, {super.key});
  final String text;
  @override
  Widget build(BuildContext context) => Text(text, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700));
}

class P extends StatelessWidget {
  const P(this.text, {super.key, this.sub = false, this.small = false, this.bold = false, this.color});
  final String text;
  final bool sub, small, bold;
  final Color? color;
  @override
  Widget build(BuildContext context) => Text(text,
      style: TextStyle(
        fontSize: small ? 13 : 15,
        fontWeight: bold ? FontWeight.w600 : FontWeight.w400,
        color: color ?? (sub ? Theme.of(context).colorScheme.onSurfaceVariant : null),
      ));
}

class Big extends StatelessWidget {
  const Big(this.text, {super.key, this.color});
  final String text;
  final Color? color;
  @override
  Widget build(BuildContext context) => Text(text, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w800, color: color));
}

class Badge2 extends StatelessWidget {
  const Badge2(this.text, {super.key, this.tone = Tone.sub});
  final String text;
  final Tone tone;
  @override
  Widget build(BuildContext context) {
    final c = toneColor(context, tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: c.withOpacity(0.14), borderRadius: BorderRadius.circular(8)),
      child: Text(text, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: c)),
    );
  }
}

class Line extends StatelessWidget {
  const Line({super.key, required this.children});
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        for (final c in children) Flexible(child: c),
      ]);
}

class Btn extends StatelessWidget {
  const Btn(this.title, {super.key, this.onPressed, this.kind = 'primary', this.busy = false});
  final String title;
  final VoidCallback? onPressed;
  final String kind; // primary | ghost | danger
  final bool busy;
  @override
  Widget build(BuildContext context) {
    final child = busy ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Text(title);
    final cb = busy ? null : onPressed;
    switch (kind) {
      case 'ghost':
        return OutlinedButton(onPressed: cb, child: child);
      case 'danger':
        return FilledButton(onPressed: cb, style: FilledButton.styleFrom(backgroundColor: const Color(0xFFDC2626)), child: child);
      default:
        return FilledButton(onPressed: cb, child: child);
    }
  }
}

class Notice extends StatelessWidget {
  const Notice({super.key, this.error, this.text, this.tone = Tone.danger});
  final Object? error;
  final String? text;
  final Tone tone;
  @override
  Widget build(BuildContext context) {
    final msg = text ?? (error == null ? null : (error is ApiError ? (error as ApiError).message : 'Something went wrong. Try again.'));
    if (msg == null || msg.isEmpty) return const SizedBox.shrink();
    final c = toneColor(context, tone);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: c.withOpacity(0.1), borderRadius: BorderRadius.circular(12), border: Border.all(color: c)),
      child: Text(msg, style: TextStyle(color: c)),
    );
  }
}

class Empty extends StatelessWidget {
  const Empty(this.text, {super.key});
  final String text;
  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.all(24), child: Center(child: P(text, sub: true)));
}

class Loading extends StatelessWidget {
  const Loading({super.key});
  @override
  Widget build(BuildContext context) => const Center(child: CircularProgressIndicator());
}

Future<void> alert(BuildContext context, String title, String body) => showDialog<void>(
      context: context,
      builder: (c) => AlertDialog(title: Text(title), content: Text(body), actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('OK'))]),
    );

Future<bool> confirmDialog(BuildContext context, String title, String body, {String yes = 'Yes', bool destructive = false}) async {
  final r = await showDialog<bool>(
    context: context,
    builder: (c) => AlertDialog(title: Text(title), content: Text(body), actions: [
      TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('No')),
      TextButton(
        onPressed: () => Navigator.pop(c, true),
        child: Text(yes, style: destructive ? const TextStyle(color: Color(0xFFDC2626)) : null),
      ),
    ]),
  );
  return r == true;
}

/// Loads data once (and again on pull-to-refresh). Rebuild with a new [keyValue] (e.g. the workspace id) to reload.
class LoadView<T> extends StatefulWidget {
  const LoadView({super.key, required this.load, required this.builder});
  final Future<T> Function() load;
  final Widget Function(BuildContext, T data, Object? error, Future<void> Function() reload, void Function(T) setData) builder;
  @override
  State<LoadView<T>> createState() => _LoadViewState<T>();
}

class _LoadViewState<T> extends State<LoadView<T>> {
  T? _data;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    try {
      final d = await widget.load();
      if (!mounted) return;
      setState(() {
        _data = d;
        _error = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) return const Loading();
    if (_data == null) {
      return ListPage(onRefresh: _reload, children: [Notice(error: _error)]);
    }
    return widget.builder(context, _data as T, _error, _reload, (v) => setState(() => _data = v));
  }
}
