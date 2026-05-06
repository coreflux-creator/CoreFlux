import { useEffect, useState } from 'react';
import { Stack, router } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { View, ActivityIndicator } from 'react-native';
import { isAuthenticated } from '@/lib/auth';
import { registerDeepLinkHandlers, registerForPushAsync } from '@/lib/notifications';

/**
 * Root layout — gates on auth status, then renders the (tabs) group
 * for authenticated users or the login stack for anonymous ones.
 *
 * Sprint 6 additions:
 *   • registerDeepLinkHandlers() routes `coreflux://approvals/<id>` and
 *     incoming push-notification taps directly to the approval detail
 *     screen.
 *   • registerForPushAsync() requests permission + persists the device
 *     push token so the backend can target this device.
 */
export default function RootLayout() {
  const [ready, setReady] = useState(false);

  useEffect(() => {
    (async () => {
      const authed = await isAuthenticated();
      if (!authed) router.replace('/login');
      setReady(true);
    })();
  }, []);

  useEffect(() => {
    const teardown = registerDeepLinkHandlers();
    // Best-effort permission + token register once the user lands here.
    registerForPushAsync();
    return teardown;
  }, []);

  if (!ready) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#0f172a' }}>
        <ActivityIndicator color="#fff" />
      </View>
    );
  }

  return (
    <>
      <StatusBar style="light" />
      <Stack screenOptions={{ headerStyle: { backgroundColor: '#0f172a' }, headerTintColor: '#fff' }}>
        <Stack.Screen name="login"   options={{ title: 'CoreFlux',   headerShown: false }} />
        <Stack.Screen name="(tabs)"  options={{ headerShown: false }} />
      </Stack>
    </>
  );
}
