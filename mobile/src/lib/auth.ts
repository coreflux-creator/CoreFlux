/**
 * Auth helpers — login, refresh, logout, device registration.
 * Wraps the JWT endpoints shipped in Sprint 2 (api/auth/mobile_*.php).
 */
import * as Storage from '../api/storage';
import { api } from '../api/client';
import * as Application from 'expo-application';
import * as Device from 'expo-device';
import { Platform } from 'react-native';

type LoginResponse = {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  refresh_expires_at: string;
  user: { id: number; name: string; email: string; role: string };
  tenant: { id: number; code: string; name: string };
};

export async function login(email: string, password: string, tenantCode?: string): Promise<LoginResponse> {
  const deviceId =
    Application.getAndroidId?.() ||
    (await Application.getIosIdForVendorAsync?.()) ||
    `web-${Date.now()}`;

  const platform = Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web';

  const r = await api<LoginResponse>('/api/auth/mobile_login', {
    method: 'POST',
    skipAuth: true,
    body: {
      email,
      password,
      tenant_code: tenantCode,
      device_id: deviceId,
      platform,
      app_version: Application.nativeApplicationVersion ?? '0.1.0',
      os_version: Device.osVersion ?? null,
      locale: undefined,
    },
  });

  await Storage.setAccessToken(r.access_token);
  await Storage.setRefreshToken(r.refresh_token);
  await Storage.setSession({ user: r.user, tenant: r.tenant });
  return r;
}

export async function logout(): Promise<void> {
  // Best-effort device revoke (no-op if endpoint unreachable).
  try {
    const deviceId =
      Application.getAndroidId?.() ||
      (await Application.getIosIdForVendorAsync?.()) ||
      null;
    if (deviceId) {
      await api(`/api/auth/mobile_devices?device_id=${encodeURIComponent(deviceId)}`, { method: 'DELETE' });
    }
  } catch { /* swallow */ }
  await Storage.clearAll();
}

export async function isAuthenticated(): Promise<boolean> {
  const t = await Storage.getAccessToken();
  return !!t;
}

export async function getCurrentSession() {
  return Storage.getSession();
}
