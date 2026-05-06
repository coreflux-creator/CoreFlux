import { useState } from 'react';
import { View, Text, TextInput, Pressable, Alert, KeyboardAvoidingView, Platform } from 'react-native';
import { router } from 'expo-router';
import { login } from '@/lib/auth';

export default function LoginScreen() {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [tenant, setTenant]     = useState('');
  const [busy, setBusy]         = useState(false);

  async function onSubmit() {
    if (!email || !password) {
      Alert.alert('Missing details', 'Email and password are required.');
      return;
    }
    setBusy(true);
    try {
      await login(email.trim(), password, tenant.trim() || undefined);
      router.replace('/(tabs)/home');
    } catch (e: unknown) {
      const msg = (e as Error)?.message ?? 'Sign-in failed';
      Alert.alert('Sign-in failed', msg);
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={{ flex: 1, backgroundColor: '#0f172a', padding: 24, justifyContent: 'center' }}
    >
      <Text style={{ color: '#fff', fontSize: 32, fontWeight: '700', marginBottom: 4 }}>CoreFlux</Text>
      <Text style={{ color: '#94a3b8', fontSize: 14, marginBottom: 32 }}>Sign in to continue</Text>

      <Text style={lbl}>Email</Text>
      <TextInput
        testID="login-email"
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="email-address"
        value={email}
        onChangeText={setEmail}
        placeholder="you@company.com"
        placeholderTextColor="#475569"
        style={fld}
      />

      <Text style={lbl}>Password</Text>
      <TextInput
        testID="login-password"
        secureTextEntry
        value={password}
        onChangeText={setPassword}
        placeholder="••••••••"
        placeholderTextColor="#475569"
        style={fld}
      />

      <Text style={lbl}>Tenant code (optional)</Text>
      <TextInput
        testID="login-tenant"
        autoCapitalize="characters"
        autoCorrect={false}
        value={tenant}
        onChangeText={setTenant}
        placeholder="ACME"
        placeholderTextColor="#475569"
        style={fld}
      />

      <Pressable
        testID="login-submit"
        disabled={busy}
        onPress={onSubmit}
        style={({ pressed }) => [btn, busy && { opacity: 0.5 }, pressed && { opacity: 0.8 }]}
      >
        <Text style={{ color: '#0f172a', fontSize: 16, fontWeight: '600' }}>{busy ? 'Signing in…' : 'Sign in'}</Text>
      </Pressable>
    </KeyboardAvoidingView>
  );
}

const lbl = { color: '#cbd5e1', fontSize: 12, marginTop: 12, marginBottom: 4, fontWeight: '500' as const };
const fld = {
  backgroundColor: '#1e293b',
  borderColor: '#334155', borderWidth: 1, borderRadius: 8,
  color: '#fff', padding: 12, fontSize: 15,
};
const btn = {
  backgroundColor: '#fff',
  padding: 14, borderRadius: 10, alignItems: 'center' as const, marginTop: 24,
};
