import 'package:flutter/material.dart';

import '../api_client.dart';
import '../helpers.dart';
import '../session.dart';
import '../ui.dart';

class _Bubble {
  _Bubble(this.key, this.mine, this.text, {this.note, this.failed = false});
  final String key;
  final bool mine;
  final String text;
  final String? note;
  final bool failed;
}

class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, this.conversationId});
  final String? conversationId;
  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final input = TextEditingController();
  final scroll = ScrollController();
  final items = <_Bubble>[];
  String? convo;
  String title = 'Chat';
  bool busy = false;
  Object? err;
  bool loaded = false;

  @override
  void initState() {
    super.initState();
    convo = widget.conversationId;
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!loaded) {
      loaded = true;
      if (convo != null) _load();
    }
  }

  Future<void> _load() async {
    final s = SessionScope.of(context);
    try {
      final r = await s.api.conversation(convo!);
      final c = (r['conversation'] as Map).cast<String, dynamic>();
      final msgs = (r['messages'] as List).map((e) => (e as Map).cast<String, dynamic>()).where((m) => m['role'] != 'system');
      if (!mounted) return;
      setState(() {
        title = (c['title'] as String?) ?? 'Chat';
        items
          ..clear()
          ..addAll(msgs.map((m) => _Bubble('${m['id']}', m['role'] == 'user', '${m['content'] ?? ''}', failed: m['status'] == 'failed')));
      });
      _toEnd();
    } catch (e) {
      if (mounted) setState(() => err = e);
    }
  }

  void _toEnd() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (scroll.hasClients) scroll.animateTo(scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 200), curve: Curves.easeOut);
      });

  Future<void> send() async {
    final q = input.text.trim();
    if (q.isEmpty || busy) return;
    final s = SessionScope.of(context);
    final mine = _Bubble('u${DateTime.now().microsecondsSinceEpoch}', true, q);
    setState(() {
      input.clear();
      err = null;
      busy = true;
      items.add(mine);
    });
    _toEnd();
    try {
      final r = await s.api.sendChat(q, convo);
      if (r['conversation_id'] != null) convo = r['conversation_id'] as String;
      final e = r['error'] is Map ? (r['error'] as Map).cast<String, dynamic>() : null;
      final failure = e == null ? null : (chatErr['${e['code']}'] ?? '${e['message']}');
      final text = '${r['text'] ?? ''}';
      setState(() {
        if (r['title'] is String) title = r['title'] as String;
        items.add(_Bubble('${r['message_id'] ?? 'a${DateTime.now().microsecondsSinceEpoch}'}', false, text.isNotEmpty ? text : (failure ?? ''),
            failed: e != null, note: r['credits'] != null ? '${credits(r['credits'])} Credits' : null));
      });
    } catch (e) {
      setState(() {
        items.removeWhere((b) => b.key == mine.key);
        input.text = q; // keep what they typed so nothing is lost on a bad network
        if (e is ApiError && e.status == 422 && e.fields.isEmpty && chatErr.containsKey(e.code)) {
          items.add(_Bubble('e${DateTime.now().microsecondsSinceEpoch}', false, chatErr[e.code]!, failed: true));
        } else {
          err = e;
        }
      });
    } finally {
      if (mounted) setState(() => busy = false);
      _toEnd();
    }
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: Column(children: [
        Expanded(
          child: ListView(controller: scroll, padding: const EdgeInsets.all(14), children: [
            if (err != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Notice(error: err)),
            if (items.isEmpty && err == null) const Empty('Ask something…'),
            for (final b in items)
              Align(
                alignment: b.mine ? Alignment.centerRight : Alignment.centerLeft,
                child: Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.88),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: b.mine ? cs.primary : Theme.of(context).cardColor,
                    borderRadius: BorderRadius.circular(16),
                    border: b.mine ? null : Border.all(color: b.failed ? const Color(0xFFDC2626) : Theme.of(context).dividerColor),
                  ),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SelectableText(b.text, style: TextStyle(fontSize: 16, height: 1.4, color: b.mine ? cs.onPrimary : (b.failed ? const Color(0xFFDC2626) : null))),
                    if (b.note != null) Padding(padding: const EdgeInsets.only(top: 4), child: P(b.note!, sub: true, small: true)),
                  ]),
                ),
              ),
            if (busy) const Align(alignment: Alignment.centerLeft, child: Padding(padding: EdgeInsets.all(8), child: SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2)))),
          ]),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Expanded(
                child: TextField(
                  controller: input,
                  minLines: 1,
                  maxLines: 5,
                  maxLength: 20000,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(hintText: 'Write a message…', counterText: '', border: OutlineInputBorder()),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(onPressed: input.text.trim().isEmpty || busy ? null : send, child: const Text('Send')),
            ]),
          ),
        ),
      ]),
    );
  }
}
