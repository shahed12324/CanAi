import 'package:flutter/material.dart';

import '../helpers.dart';
import '../session.dart';
import '../ui.dart';
import 'chat.dart';

class ChatListTab extends StatefulWidget {
  const ChatListTab({super.key});
  @override
  State<ChatListTab> createState() => _ChatListTabState();
}

class _ChatListTabState extends State<ChatListTab> {
  int round = 0; // bump to reload after coming back from a chat

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    return LoadView<List<Map<String, dynamic>>>(
      key: ValueKey(round),
      load: s.api.conversations,
      builder: (context, list, error, reload, _) {
        Future<void> open(String? id) async {
          await Navigator.push(context, MaterialPageRoute<void>(builder: (_) => ChatScreen(conversationId: id)));
          if (mounted) setState(() => round++);
        }

        return ListPage(onRefresh: reload, children: [
          Btn('＋ New chat', onPressed: () => open(null)),
          Notice(error: error),
          if (list.isEmpty) const Empty('No chats yet. Tap the button above.'),
          for (final c in list) Panel(onTap: () => open(c['id'] as String), children: [P((c['title'] as String?) ?? 'New chat', bold: true), P(when(c['last_message_at'] as String?), sub: true, small: true)]),
        ]);
      },
    );
  }
}
