import * as Notifications from 'expo-notifications';
import { Stack, useRouter } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { routeForPush } from '@/lib/refresh';
import { SessionProvider } from '@/state/session';
import { StepUpProvider } from '@/state/stepup';

function Shell() {
  const router = useRouter();
  useEffect(() => {
    // Tapping a push opens what it is about. Only our own two screens are reachable this way.
    const sub = Notifications.addNotificationResponseReceivedListener((r) => {
      router.push(routeForPush(r.notification.request.content.data));
    });
    return () => sub.remove();
  }, [router]);
  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Screen name="scan" options={{ headerShown: true, title: 'Scan QR code' }} />
      <Stack.Screen name="notifications" options={{ headerShown: true, title: 'Notifications' }} />
      <Stack.Screen name="usage" options={{ headerShown: true, title: 'Usage and credits' }} />
      <Stack.Screen name="health" options={{ headerShown: true, title: 'System health' }} />
      <Stack.Screen name="computer" options={{ headerShown: true, title: 'My Computer' }} />
      <Stack.Screen name="security" options={{ headerShown: true, title: 'Security and PIN' }} />
      <Stack.Screen name="workspaces" options={{ headerShown: true, title: 'Workspace' }} />
      <Stack.Screen name="chat/[id]" options={{ headerShown: true, title: 'Chat' }} />
    </Stack>
  );
}

export default function Root() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <StepUpProvider>
          <StatusBar style="auto" />
          <Shell />
        </StepUpProvider>
      </SessionProvider>
    </SafeAreaProvider>
  );
}
