import { useRouter } from 'expo-router';
import { Badge, Btn, Card, Empty, H, Loading, Notice, P, Row, Screen } from '@/components/ui';
import { bn, when } from '@/lib/format';
import { useSession } from '@/state/session';
import { useLoad } from '@/state/useLoad';

const STATUS: Record<string, [string, 'ok' | 'warn' | 'sub' | 'danger']> = {
  active: ['Running', 'ok'], paused: ['Paused', 'warn'], draft: ['Draft', 'sub'], done: ['Done', 'ok'],
  queued: ['Queued', 'sub'], running: ['Running', 'ok'], cancelled: ['Reject', 'danger'],
};

export default function Monitor() {
  const router = useRouter();
  const { api, tenant, boot } = useSession();
  const m = useLoad(() => api.automations(), [tenant]);
  if (m.loading && !m.data) return <Loading />;
  const d = m.data;
  return (
    <Screen onRefresh={m.reload}>
      <Notice error={m.error} />
      <P sub small>What runs on its own in your workspace. Agents and workflows will be added here in a later step.</P>
      {boot?.can?.health ? <Btn title="View system health" kind="ghost" onPress={() => router.push('/health')} /> : null}
      <H>Email campaigns</H>
      {d && d.campaigns.length === 0 ? <Empty text="No campaigns." /> : null}
      {d?.campaigns.map((c) => {
        const [label, tone] = STATUS[c.status] ?? [c.status, 'sub' as const];
        return (
          <Card key={c.id}>
            <Row between><H>{c.name}</H><Badge text={label} tone={tone} /></Row>
            <P sub small>Waiting for approval {bn(c.pending)} · in send queue {bn(c.queued)} · Sent {bn(c.sent)} · Replies {bn(c.replied)}{c.failed > 0 ? ` · Failed ${bn(c.failed)}` : ''}</P>
          </Card>
        );
      })}
      <H>Lead research</H>
      {d && d.research_jobs.length === 0 ? <Empty text="No research jobs." /> : null}
      {d?.research_jobs.map((j) => {
        const [label, tone] = STATUS[j.status] ?? [j.status, 'sub' as const];
        return (
          <Card key={j.id}>
            <Row between><H>{j.name}</H><Badge text={label} tone={tone} /></Row>
            <P sub small>{bn(j.total)} sites · {when(j.created_at)}</P>
          </Card>
        );
      })}
    </Screen>
  );
}
