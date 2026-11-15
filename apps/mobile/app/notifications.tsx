import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { Pressable } from 'react-native';
import { Card, Empty, H, Loading, Notice, P, Row, Screen, Badge } from '@/components/ui';
import { when } from '@/lib/format';
import { useSession } from '@/state/session';
import { useLoad } from '@/state/useLoad';

export default function Notifications() {
  const router = useRouter();
  const { api, tenant } = useSession();
  const n = useLoad(() => api.notifications(false), [tenant]);
  useEffect(() => { if (n.data?.some((x) => !x.read)) { api.markRead('all').catch(() => undefined); } }, [api, n.data]);   // opening the list reads everything
  if (n.loading && !n.data) return <Loading />;
  return (
    <Screen onRefresh={n.reload}>
      <Notice error={n.error} />
      {(n.data ?? []).length === 0 ? <Empty text="No notifications." /> : (n.data ?? []).map((x) => (
        <Pressable key={x.id} onPress={() => x.kind === 'approvals_pending' ? router.push('/approvals') : undefined}>
          <Card tone={x.kind === 'emergency' ? 'danger' : undefined}>
            <Row between><H>{x.title}</H>{x.read ? null : <Badge text="New" tone="primary" />}</Row>
            {x.body ? <P sub>{x.body}</P> : null}
            <P sub small>{when(x.created_at)}</P>
          </Card>
        </Pressable>
      ))}
    </Screen>
  );
}
