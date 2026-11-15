import { Badge, Card, H, Loading, Notice, P, Row, Screen } from '@/components/ui';
import { bn } from '@/lib/format';
import { useSession } from '@/state/session';
import { useLoad } from '@/state/useLoad';

export default function HealthScreen() {
  const { api, tenant } = useSession();
  const h = useLoad(() => api.health(), [tenant]);
  if (h.loading && !h.data) return <Loading />;
  const d = h.data;
  return (
    <Screen onRefresh={h.reload}>
      <Notice error={h.error} />
      {d ? (
        <>
          <Card tone={d.ok ? 'ok' : 'warn'}><H>{d.ok ? '✅ All good' : '⚠ Needs attention'}</H></Card>
          <Card><Row between><H>AI engine</H><Badge text={d.ai_engine ? 'On' : 'Not connected'} tone={d.ai_engine ? 'ok' : 'danger'} /></Row></Card>
          <Card><Row between><H>Emergency stop</H><Badge text={d.emergency.active ? 'On (everything paused)' : 'Off'} tone={d.emergency.active ? 'danger' : 'ok'} /></Row></Card>
          <Card><Row between><H>Waiting for approval</H><Badge text={bn(d.pending_approvals)} tone={d.pending_approvals > 0 ? 'warn' : 'ok'} /></Row></Card>
          <Card><Row between><H>Failed emails in the last 24 hours</H><Badge text={bn(d.failed_mails_24h)} tone={d.failed_mails_24h > 0 ? 'danger' : 'ok'} /></Row></Card>
          <H>Email account problems</H>
          {d.email_accounts_with_errors.length === 0 ? <P sub>No problems.</P> : d.email_accounts_with_errors.map((a) => (
            <Card key={a.id} tone="danger"><H>{a.name}</H><P sub small>{a.from} · {a.provider}</P><P color="#dc2626">{a.error}</P></Card>
          ))}
        </>
      ) : null}
    </Screen>
  );
}
