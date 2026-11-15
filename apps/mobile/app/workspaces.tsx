import { useRouter } from 'expo-router';
import { Alert, Pressable } from 'react-native';
import { Badge, Card, H, P, Row, Screen } from '@/components/ui';
import { useSession } from '@/state/session';

export default function Workspaces() {
  const router = useRouter();
  const { boot, tenant, switchTenant } = useSession();
  return (
    <Screen>
      {(boot?.workspaces ?? []).map((w) => (
        <Pressable key={w.id} onPress={async () => {
          try { await switchTenant(w.id); router.replace('/home'); } catch { Alert.alert('Failed', 'The workspace could not be switched.'); }
        }}>
          <Card tone={w.id === tenant ? 'ok' : undefined}>
            <Row between><H>{w.name}</H>{w.id === tenant ? <Badge text="Current" tone="ok" /> : null}</Row>
            <P sub small>{w.type === 'personal' ? 'Personal' : 'Organization'}</P>
          </Card>
        </Pressable>
      ))}
    </Screen>
  );
}
