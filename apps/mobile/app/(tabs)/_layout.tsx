import { Redirect, Tabs } from 'expo-router';
import { Text } from 'react-native';
import { Loading, useTheme } from '@/components/ui';
import { useSession } from '@/state/session';

const icon = (glyph: string) => ({ color }: { color: unknown }) => <Text style={{ fontSize: 20, color: color as string }}>{glyph}</Text>;

export default function TabsLayout() {
  const t = useTheme();
  const { status } = useSession();
  if (status === 'loading') return <Loading />;
  if (status === 'out') return <Redirect href="/login" />;
  return (
    <Tabs screenOptions={{ tabBarActiveTintColor: t.primary, tabBarStyle: { backgroundColor: t.card, borderTopColor: t.line }, headerStyle: { backgroundColor: t.card }, headerTitleStyle: { color: t.text }, headerShadowVisible: false }}>
      <Tabs.Screen name="home" options={{ title: 'Home', tabBarIcon: icon('⌂') }} />
      <Tabs.Screen name="chat" options={{ title: 'Chat', tabBarIcon: icon('✎') }} />
      <Tabs.Screen name="approvals" options={{ title: 'Approvals', tabBarIcon: icon('✓') }} />
      <Tabs.Screen name="monitor" options={{ title: 'Monitor', tabBarIcon: icon('◔') }} />
      <Tabs.Screen name="more" options={{ title: 'More', tabBarIcon: icon('≡') }} />
    </Tabs>
  );
}
