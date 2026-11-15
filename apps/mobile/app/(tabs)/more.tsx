import { useRouter } from 'expo-router';
import { Alert, Pressable } from 'react-native';
import { Badge, Btn, Card, H, P, Row, Screen } from '@/components/ui';
import { bn, versionAtLeast } from '@/lib/format';
import { APP_VERSION, useSession } from '@/state/session';
import { useLoad } from '@/state/useLoad';

export default function More() {
  const router = useRouter();
  const { api, boot, tenant, baseUrl, signOut } = useSession();
  const unread = useLoad(async () => (await api.notifications(true)).length, [tenant]);
  const ws = boot?.workspaces.find((w) => w.id === tenant);
  const outdated = boot ? !versionAtLeast(APP_VERSION, boot.min_app_version) : false;

  const Item = ({ title, sub, to, badge }: { title: string; sub?: string; to: string; badge?: string }) => (
    <Pressable onPress={() => router.push(to as never)}>
      <Card><Row between><H>{title}</H>{badge ? <Badge text={badge} tone="primary" /> : null}</Row>{sub ? <P sub small>{sub}</P> : null}</Card>
    </Pressable>
  );

  return (
    <Screen>
      {outdated ? <Card tone="warn"><H>Update the app</H><P sub small>A newer app version is needed for this server ({boot?.min_app_version})). Some features may not work.</P></Card> : null}
      <Card>
        <H>{boot?.user.name}</H>
        <P sub small>{boot?.user.email}</P>
        <P sub small>Workspace: {ws?.name ?? '—'}</P>
      </Card>
      <Item title="Notifications" to="/notifications" badge={unread.data ? bn(unread.data) : undefined} />
      <Item title="Usage and credits" sub="How many credits were spent" to="/usage" />
      <Item title="My Computer" sub="Approve your computer’s pending jobs" to="/computer" />
      <Item title="Security and PIN" sub="Approval PIN, fingerprint" to="/security" />
      {(boot?.workspaces.length ?? 0) > 1 ? <Item title="Switch workspace" to="/workspaces" /> : null}
      {boot?.can?.health ? <Item title="System health" sub="AI engine, email accounts" to="/health" /> : null}
      <P sub small>Server: {baseUrl} · version {APP_VERSION}</P>
      <Btn title="Log out" kind="danger" onPress={() => new Promise<void>((done) => Alert.alert('Log out', 'Log out of this phone?', [
        { text: 'No', style: 'cancel', onPress: () => done() },
        { text: 'Yes', style: 'destructive', onPress: async () => { await signOut(); done(); } },
      ]))} />
    </Screen>
  );
}
