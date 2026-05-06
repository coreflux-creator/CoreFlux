import { useEffect, useState } from 'react';
import { View, Text, Pressable, Alert } from 'react-native';
import { router } from 'expo-router';
import Constants from 'expo-constants';
import { logout, getCurrentSession } from '@/lib/auth';
import type { SessionShape } from '@/api/storage';

export default function ProfileScreen() {
  const [s, setS] = useState<SessionShape | null>(null);
  useEffect(() => { getCurrentSession().then(setS); }, []);

  async function onLogout() {
    Alert.alert('Sign out?', 'You will be returned to the sign-in screen.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Sign out', style: 'destructive', onPress: async () => {
        await logout();
        router.replace('/login');
      } },
    ]);
  }

  return (
    <View style={{ flex: 1, padding: 20 }} testID="profile-screen">
      <Text style={{ color: '#64748b', fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.5 }}>Signed in as</Text>
      <Text style={{ fontSize: 20, fontWeight: '700', marginTop: 4 }}>{s?.user?.name ?? '—'}</Text>
      <Text style={{ color: '#475569', marginTop: 2 }}>{s?.user?.email ?? ''}</Text>

      <View style={{ height: 24 }} />

      <Row label="Tenant"   value={s?.tenant?.name  ?? '—'} />
      <Row label="Role"     value={s?.user?.role    ?? '—'} />
      <Row label="App"      value={`${Constants.expoConfig?.name ?? 'CoreFlux'} ${Constants.expoConfig?.version ?? ''}`} />

      <Pressable onPress={onLogout} testID="profile-logout" style={btn}>
        <Text style={{ color: '#dc2626', fontWeight: '600', fontSize: 15 }}>Sign out</Text>
      </Pressable>
    </View>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' }}>
      <Text style={{ color: '#64748b', fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</Text>
      <Text style={{ fontSize: 16, marginTop: 4 }}>{value}</Text>
    </View>
  );
}

const btn = {
  marginTop: 32, padding: 14, borderRadius: 10, alignItems: 'center' as const,
  borderWidth: 1, borderColor: '#fecaca', backgroundColor: '#fff',
};
