import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, KeyboardAvoidingView, Platform, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ApiError } from '@/api/client';
import { Btn, Notice, useTheme } from '@/components/ui';
import { credits } from '@/lib/format';
import { useSession } from '@/state/session';

interface Bubble { key: string; role: 'user' | 'assistant'; text: string; note?: string; failed?: boolean }

const ERR: Record<string, string> = {
  insufficient_credits: 'Not enough credits. Buy credits on the website.',
  spending_limit_reached: 'The monthly spending limit has been reached.',
  no_model: 'No AI model is enabled on your plan.',
};

export default function ChatScreen() {
  const t = useTheme();
  const nav = useNavigation();
  const insets = useSafeAreaInsets();
  const { id } = useLocalSearchParams<{ id: string }>();
  const { api, tenant } = useSession();
  const convo = useRef<string | null>(id && id !== 'new' ? id : null);
  const [items, setItems] = useState<Bubble[]>([]);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<unknown>(null);
  const list = useRef<FlatList<Bubble>>(null);

  useEffect(() => {
    if (!convo.current) return;
    (async () => {
      try {
        const r = await api.conversation(convo.current!);
        nav.setOptions({ title: r.conversation.title ?? 'Chat' });
        setItems(r.messages.filter((m) => m.role !== 'system').map((m) => ({ key: m.id, role: m.role === 'user' ? 'user' : 'assistant', text: m.content, failed: m.status === 'failed' })));
      } catch (e) { setErr(e); }
    })();
  }, [api, nav, tenant]);

  const send = useCallback(async () => {
    const q = text.trim();
    if (!q || busy) return;
    setText(''); setErr(null); setBusy(true);
    const mine: Bubble = { key: `u${Date.now()}`, role: 'user', text: q };
    setItems((cur) => [...cur, mine]);
    try {
      const r = await api.sendChat(q, convo.current);
      if (r.conversation_id) convo.current = r.conversation_id;
      if (r.title) nav.setOptions({ title: r.title });
      const failure = r.error ? (ERR[r.error.code] ?? r.error.message) : undefined;
      setItems((cur) => [...cur, { key: r.message_id ?? `a${Date.now()}`, role: 'assistant', text: r.text || failure || '', failed: !!r.error, note: r.credits ? `${credits(r.credits)} Credits` : undefined }]);
    } catch (e) {
      const code = e instanceof ApiError ? e.code : '';
      if (e instanceof ApiError && e.status === 422 && e.fields && Object.keys(e.fields).length === 0 && ERR[code]) setItems((cur) => [...cur, { key: `e${Date.now()}`, role: 'assistant', text: ERR[code]!, failed: true }]);
      else setErr(e);
      setText(q);                                  // keep what they typed so nothing is lost on a bad network
      setItems((cur) => cur.filter((b) => b.key !== mine.key));
    } finally { setBusy(false); }
  }, [api, busy, nav, text]);

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: t.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <FlatList
        ref={list}
        data={items}
        keyExtractor={(b) => b.key}
        contentContainerStyle={{ padding: 14, gap: 10 }}
        onContentSizeChange={() => list.current?.scrollToEnd({ animated: true })}
        ListHeaderComponent={err ? <Notice error={err} /> : undefined}
        ListEmptyComponent={<Text style={{ color: t.sub, textAlign: 'center', padding: 30 }}>Ask something…</Text>}
        renderItem={({ item }) => (
          <View style={{ alignSelf: item.role === 'user' ? 'flex-end' : 'flex-start', maxWidth: '88%', backgroundColor: item.role === 'user' ? t.primary : t.card, borderRadius: 16, padding: 12, borderWidth: item.role === 'user' ? 0 : 1, borderColor: item.failed ? t.danger : t.line }}>
            <Text selectable style={{ color: item.role === 'user' ? '#fff' : item.failed ? t.danger : t.text, fontSize: 16, lineHeight: 23 }}>{item.text}</Text>
            {item.note ? <Text style={{ color: t.sub, fontSize: 12, marginTop: 4 }}>{item.note}</Text> : null}
          </View>
        )}
        ListFooterComponent={busy ? <View style={{ padding: 10, alignItems: 'flex-start' }}><ActivityIndicator /></View> : undefined}
      />
      <View style={{ flexDirection: 'row', gap: 8, padding: 10, paddingBottom: insets.bottom + 10, backgroundColor: t.card, borderTopColor: t.line, borderTopWidth: 1, alignItems: 'flex-end' }}>
        <TextInput value={text} onChangeText={setText} multiline placeholder="Write a message…" placeholderTextColor={t.sub} maxLength={20000}
          style={{ flex: 1, maxHeight: 130, color: t.text, backgroundColor: t.bg, borderRadius: 14, paddingHorizontal: 14, paddingVertical: 10, fontSize: 16, borderWidth: 1, borderColor: t.line }} />
        <View style={{ width: 84 }}><Btn small title="Send" onPress={send} disabled={!text.trim() || busy} /></View>
      </View>
    </KeyboardAvoidingView>
  );
}
