import { Alert } from 'react-native';
import { ApiError } from '@/api/client';
import type { Decision, PcAction, UserMode } from '@/api/types';
import { useState } from 'react';
import { Badge, Btn, Field, Card, Empty, H, Loading, Notice, P, Row, Screen } from '@/components/ui';
import { bn } from '@/lib/format';
import { HANDOFF, KIND, MODE_TEXT, REFUSED, RISK, WHY, cleanTarget, isHighRisk, minutesLeft, stepsNeedingApproval, summary } from '@/lib/pc';
import { useSession } from '@/state/session';
import { useSensitive } from '@/state/stepup';
import { useAutoRefresh } from '@/state/useAutoRefresh';
import { useLoad } from '@/state/useLoad';

/** The person's own computer asking for permission. High-risk actions can be approved ONLY here (PIN required). */
export default function Computer() {
  const { api, tenant } = useSession();
  const run = useSensitive();
  const list = useLoad(() => api.pcActions(), [tenant]);
  const devices = useLoad(() => api.pcDevices(), [tenant]);
  const plans = useLoad(() => api.pcPlans(), [tenant]);
  const mode = useLoad(() => api.userMode(), [tenant]);
  useAutoRefresh(async () => (await Promise.all([list.reload(), plans.reload()])).every(Boolean));          // push -> instant; polling when push is off
  const [kind, setKind] = useState(HANDOFF[0]!.kind);
  const [target, setTarget] = useState('');
  const [deviceId, setDeviceId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const devs = devices.data ?? [];
  const chosen = deviceId ?? (devs.length === 1 ? devs[0]!.id : null);
  const hand = HANDOFF.find((h) => h.kind === kind)!;

  const send = async () => {
    const t = cleanTarget(target);
    if (!chosen || t === null) { Alert.alert('Failed', chosen ? 'The text is empty or too long.' : 'Which computer — pick one.'); return; }
    setBusy(true);
    try {
      await api.pcRequest(chosen, kind, t);
      setTarget('');
      await list.reload();
    } catch (e) {
      Alert.alert('Failed', e instanceof ApiError ? (e.code === 'refused' ? REFUSED : e.message) : 'Try again.');
    } finally { setBusy(false); }
  };

  const decide = async (a: PcAction, decision: Decision) => {
    try {
      await run(() => api.decidePc(a.id, decision));
      list.setData((cur) => (cur ?? []).filter((x) => x.id !== a.id));
    } catch (e) {
      if (e instanceof ApiError && e.code === 'cancelled') return;
      Alert.alert('Failed', e instanceof ApiError ? (WHY[e.code] ?? e.message) : 'Try again.');
      if (e instanceof ApiError && ['not_pending', 'expired', 'emergency_stop', 'not_found'].includes(e.code)) await list.reload();
    }
  };

  const approveStep = async (planId: string, step: string, label: string, target: string) => {
    Alert.alert(`${label} — Approve this step?`, `${target}\n\n⚠ Only this step. Your PIN is asked.`, [
      { text: 'No', style: 'cancel' },
      { text: 'Approve', onPress: async () => {
        try { await run(() => api.approvePlanStep(planId, step)); await plans.reload(); }
        catch (e) {
          if (e instanceof ApiError && e.code === 'cancelled') return;
          Alert.alert('Failed', e instanceof ApiError ? (WHY[e.code] ?? e.message) : 'Try again.');
          await plans.reload();
        }
      } },
    ]);
  };

  const switchMode = async (m: UserMode) => {
    try { await api.setUserMode(m); await mode.reload(); await list.reload(); await plans.reload(); }
    catch (e) { Alert.alert('Failed', e instanceof ApiError ? e.message : 'Try again.'); }
  };

  const confirm = (a: PcAction) => Alert.alert(
    `${KIND[a.kind] ?? a.kind} — Approve?`,
    `${a.device}\n${summary(a).join('\n')}${isHighRisk(a) ? '\n\n⚠ High-risk job. Continue only if you are sure.' : ''}`,
    [{ text: 'No', style: 'cancel' }, { text: 'Approvals', onPress: () => void decide(a, 'approve') }],
  );

  if (list.loading && !list.data) return <Loading />;
  const rows = list.data ?? [];
  return (
    <Screen onRefresh={list.reload}>
      <Notice error={list.error} />
      <Card>
        <H>Mode</H>
        <Row>
          <Btn small kind={(mode.data?.mode ?? 'agent') === 'companion' ? 'primary' : 'ghost'} title="Companion" onPress={() => void switchMode('companion')} />
          <Btn small kind={(mode.data?.mode ?? 'agent') === 'agent' ? 'primary' : 'ghost'} title="Agent" onPress={() => void switchMode('agent')} />
        </Row>
        <P sub small>{MODE_TEXT[mode.data?.mode ?? 'agent']}</P>
      </Card>
      {(plans.data ?? []).filter((p) => stepsNeedingApproval(p).length > 0).map((p) => (
        <Card key={p.id} tone="warn">
          <Row between><H>Plan: {p.goal.length > 80 ? `${p.goal.slice(0, 80)}…` : p.goal}</H><Badge text={p.status} tone="sub" /></Row>
          <P sub small>{p.device} — {bn(minutesLeft(p.expires_at))} minutes left</P>
          {stepsNeedingApproval(p).map((st) => (
            <Row key={st.id} between>
              <P small>{st.label}{st.target ? `: ${st.target}` : ''}</P>
              <Btn small title={st.high ? 'Approve (PIN)' : 'Approve'} onPress={() => void approveStep(p.id, st.id, st.label, st.target)} />
            </Row>
          ))}
        </Card>
      ))}
      <P sub small>What your computer wants to do arrives here. It expires in 10 minutes. Approving needs your PIN.</P>
      <Card>
        <H>Send to computer</H>
        {devs.length === 0 ? <P sub small>No computer is paired — on the web, open the "My Computer" page and pair one.</P> : (
          <>
            {devs.length > 1 ? <Row>{devs.map((d) => <Btn key={d.id} small kind={chosen === d.id ? 'primary' : 'ghost'} title={d.name} onPress={() => setDeviceId(d.id)} />)}</Row> : <P sub small>{devs[0]!.name}</P>}
            <Row>{HANDOFF.map((h) => <Btn key={h.kind} small kind={kind === h.kind ? 'primary' : 'ghost'} title={h.label} onPress={() => setKind(h.kind)} />)}</Row>
            <Field label={hand.hint} value={target} onChangeText={setTarget} autoCapitalize="none" autoCorrect={false} maxLength={520} />
            <Btn title="Send" busy={busy} onPress={send} />
            <P sub small>Requests will appear in this list; if they follow your computer’s rules you approve them right here.</P>
          </>
        )}
      </Card>
      {rows.length === 0 ? <Empty text="No jobs are waiting right now. ✅" /> : rows.map((a) => (
        <Card key={a.id} tone={isHighRisk(a) ? 'warn' : undefined}>
          <Row between><H>{KIND[a.kind] ?? a.kind}</H><Badge text={`Risk: ${RISK[a.risk]}`} tone={isHighRisk(a) ? 'danger' : 'sub'} /></Row>
          <P sub small>{a.device}</P>
          {summary(a).map((l) => <P key={l} small>{l}</P>)}
          <P sub small> {bn(minutesLeft(a.expires_at))} minutes left</P>
          <Row>
            <Btn small title="Approvals" onPress={() => confirm(a)} />
            <Btn small kind="ghost" title="Reject" onPress={() => decide(a, 'reject')} />
          </Row>
        </Card>
      ))}
    </Screen>
  );
}
