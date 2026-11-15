import { Alert } from 'react-native';
import { ApiError } from '@/api/client';
import type { Approval, Decision } from '@/api/types';
import { Btn, Card, Empty, H, Loading, Notice, P, Row, Screen } from '@/components/ui';
import { bn, when } from '@/lib/format';
import { useSession } from '@/state/session';
import { useSensitive } from '@/state/stepup';
import { useAutoRefresh } from '@/state/useAutoRefresh';
import { useLoad } from '@/state/useLoad';

const WHY: Record<string, string> = {
  not_pending: 'This is no longer waiting — someone else has decided.',
  suppressed: 'This address is on the block/unsubscribe list.',
  emergency_stop: 'Emergency stop is on. Turn jobs back on first.',
  pin_locked: 'Too many wrong PINs. Try again in 15 minutes.',
  no_pin: 'First go to "More → Security" and set a PIN.',
};

export default function Approvals() {
  const { api, tenant, boot } = useSession();
  const run = useSensitive();
  const list = useLoad(() => api.approvals(), [tenant]);
  const canApprove = boot?.can?.approve ?? false;
  useAutoRefresh(list.reload);

  const decide = async (a: Approval, decision: Decision) => {
    try {
      await run(() => api.decide(a.kind, a.ref, decision));
      list.setData((cur) => (cur ?? []).filter((x) => x.ref !== a.ref));
    } catch (e) {
      if (e instanceof ApiError && e.code === 'cancelled') return;
      Alert.alert('Failed', e instanceof ApiError ? (WHY[e.code] ?? e.message) : 'Try again.');
      if (e instanceof ApiError && e.code === 'not_pending') await list.reload();
    }
  };

  if (list.loading && !list.data) return <Loading />;
  return (
    <Screen onRefresh={list.reload}>
      <Notice error={list.error} />
      {!canApprove ? <Notice tone="warn" text="You can view, but you do not have permission to approve." /> : null}
      <P sub small>No email is sent unless you approve it here. Approving needs your PIN.</P>
      {(list.data ?? []).length === 0 ? <Empty text="Nothing is waiting right now. ✅" /> : (list.data ?? []).map((a) => (
        <Card key={a.ref}>
          <H>{a.title}</H>
          <P sub>{a.summary}</P>
          <P sub small>{when(a.created_at)}</P>
          {canApprove ? (
            <Row>
              <Btn small title="Approvals" onPress={() => decide(a, 'approve')} />
              <Btn small kind="ghost" title="Reject" onPress={() => decide(a, 'reject')} />
            </Row>
          ) : null}
        </Card>
      ))}
      {(list.data ?? []).length > 0 ? <P sub small>Total {bn((list.data ?? []).length)} waiting</P> : null}
    </Screen>
  );
}
