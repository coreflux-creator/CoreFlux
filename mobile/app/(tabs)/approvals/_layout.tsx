import { Stack } from 'expo-router';

/**
 * Approvals stack — list (index) + detail ([id]) pages so push
 * notifications can deep-link straight to a single bill / workflow
 * instance via `coreflux://approvals/<id>` or
 * `https://app.coreflux.com/approvals/<id>`.
 */
export default function ApprovalsLayout() {
  return (
    <Stack screenOptions={{ headerStyle: { backgroundColor: '#0f172a' }, headerTintColor: '#fff' }}>
      <Stack.Screen name="index" options={{ title: 'Approvals', headerShown: false }} />
      <Stack.Screen name="[id]"  options={{ title: 'Review approval' }} />
    </Stack>
  );
}
