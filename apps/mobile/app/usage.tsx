import { View } from 'react-native';
import { Big, Card, Empty, H, Loading, Notice, P, Row, Screen, useTheme } from '@/components/ui';
import { bn, credits } from '@/lib/format';
import { useSession } from '@/state/session';
import { useLoad } from '@/state/useLoad';

export default function Usage() {
  const t = useTheme();
  const { api, tenant } = useSession();
  const u = useLoad(() => api.usage(), [tenant]);
  if (u.loading && !u.data) return <Loading />;
  const d = u.data;
  const max = Math.max(1, ...(d?.daily ?? []).map((x) => Number(x.credits)));
  return (
    <Screen onRefresh={u.reload}>
      <Notice error={u.error} />
      {d ? (
        <>
          <Card><P sub small>Balance</P><Big>{credits(d.balance)}</Big></Card>
          <Card>
            <P sub small>Spent this month</P><Big>{credits(d.spent_this_month)}</Big>
            {d.monthly_limit ? <P sub small>Monthly limit: {credits(d.monthly_limit)}</P> : null}
          </Card>
          <H>Last 30 days</H>
          {d.daily.length === 0 ? <Empty text="No spending yet." /> : (
            <Card>
              {d.daily.map((x) => (
                <Row key={x.day}>
                  <P small sub>{bn(x.day.slice(5).replace('-', '/'))}</P>
                  <View style={{ flex: 1, height: 10, backgroundColor: t.line, borderRadius: 5 }}><View style={{ width: `${Math.max(3, (Number(x.credits) / max) * 100)}%`, height: 10, backgroundColor: t.primary, borderRadius: 5 }} /></View>
                  <P small>{credits(x.credits)}</P>
                </Row>
              ))}
            </Card>
          )}
        </>
      ) : null}
    </Screen>
  );
}
