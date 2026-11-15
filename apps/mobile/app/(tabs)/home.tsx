import { useRouter } from 'expo-router';
import { Alert, Pressable } from 'react-native';
import { ApiError } from '@/api/client';
import { Badge, Big, Btn, Card, Empty, H, Loading, Notice, P, Row, Screen, useTheme } from '@/components/ui';
import { bn, credits, when } from '@/lib/format';
import { useSession } from '@/state/session';
import { useSensitive } from '@/state/stepup';
import { useLoad } from '@/state/useLoad';

export default function Home() {
  const t = useTheme();
  const router = useRouter();
  const { api, boot, tenant } = useSession();
  const run = useSensitive();
  const d = useLoad(() => api.dashboard(), [tenant]);
  const canStop = boot?.can?.emergency ?? false;

  if (d.loading && !d.data) return <Loading />;
  const x = d.data;
  const stopped = x?.emergency.active ?? false;

  const stop = () => Alert.alert('Emergency stop', 'All automatic jobs in this workspace (sending emails, new approvals) will stop right now. You need your PIN to turn them back on.', [
    { text: 'No', style: 'cancel' },
    { text: 'Stop', style: 'destructive', onPress: async () => { try { await api.setEmergency('stop'); await d.reload(); } catch (e) { Alert.alert('Failed', e instanceof ApiError ? e.message : 'Try again.'); } } },
  ]);
  const resume = async () => {
    try { await run(() => api.setEmergency('resume')); await d.reload(); } catch (e) { if (!(e instanceof ApiError && e.code === 'cancelled')) Alert.alert('Failed', e instanceof ApiError ? e.message : 'Try again.'); }
  };

  return (
    <Screen onRefresh={d.reload}>
      <Notice error={d.error} />
      {stopped ? (
        <Card tone="danger">
          <H>⛔ Emergency stop is on</H>
          <P sub>All automatic jobs are paused{x?.emergency.since ? ` (${when(x.emergency.since)})` : ''}.{x?.emergency.reason ? ` Reason: ${x.emergency.reason}` : ''}</P>
          {canStop ? <Btn title="Turn back on (PIN needed)" onPress={resume} /> : <P small sub>Only the owner/admin may turn it back on.</P>}
        </Card>
      ) : null}
      {x ? (
        <>
          <Card tone={x.low_balance ? 'warn' : undefined}>
            <P sub small>Credit balance</P>
            <Big color={x.low_balance ? t.warn : t.text}>{credits(x.balance)}</Big>
            <Row between><P sub small>Spent this month: {credits(x.spent_this_month)}</P>{x.low_balance ? <Badge text="Low balance" tone="warn" /> : null}</Row>
          </Card>
          <Row>
            <Pressable style={{ flex: 1 }} onPress={() => router.push('/approvals')}>
              <Card tone={x.pending_approvals > 0 ? 'warn' : undefined}><P sub small>Waiting for approval</P><Big>{bn(x.pending_approvals)}</Big></Card>
            </Pressable>
            <Pressable style={{ flex: 1 }} onPress={() => router.push('/notifications')}>
              <Card><P sub small>New notifications</P><Big>{bn(x.unread)}</Big></Card>
            </Pressable>
          </Row>
          <Card><P sub small>Leads (collected)</P><Big>{bn(x.leads)}</Big></Card>
          <H>Recent chats</H>
          {x.recent_chats.length === 0 ? <Empty text="No chats yet." /> : x.recent_chats.map((c) => (
            <Pressable key={c.id} onPress={() => router.push(`/chat/${c.id}`)}><Card><P bold>{c.title ?? 'New chat'}</P><P sub small>{when(c.last_message_at)}</P></Card></Pressable>
          ))}
          {canStop && !stopped ? <Btn title="⛔ Emergency stop" kind="danger" onPress={stop} /> : null}
        </>
      ) : null}
    </Screen>
  );
}
