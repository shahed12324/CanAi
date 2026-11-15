import { useRouter } from 'expo-router';
import { Pressable } from 'react-native';
import { Btn, Card, Empty, Loading, Notice, P, Screen } from '@/components/ui';
import { when } from '@/lib/format';
import { useSession } from '@/state/session';
import { useLoad } from '@/state/useLoad';

export default function ChatList() {
  const router = useRouter();
  const { api, tenant } = useSession();
  const c = useLoad(() => api.conversations(), [tenant]);
  if (c.loading && !c.data) return <Loading />;
  return (
    <Screen onRefresh={c.reload}>
      <Btn title="＋ New chat" onPress={() => router.push('/chat/new')} />
      <Notice error={c.error} />
      {(c.data ?? []).length === 0 ? <Empty text="No chats yet. Tap the button above." /> : (c.data ?? []).map((x) => (
        <Pressable key={x.id} onPress={() => router.push(`/chat/${x.id}`)}>
          <Card><P bold>{x.title ?? 'New chat'}</P><P sub small>{when(x.last_message_at)}</P></Card>
        </Pressable>
      ))}
    </Screen>
  );
}
