/**
 * Secure-store wrapper. Falls back to in-memory storage on web (where
 * SecureStore is unavailable) so the SPA + native app share one auth helper.
 */
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

const ACCESS_KEY  = 'coreflux.access_token';
const REFRESH_KEY = 'coreflux.refresh_token';
const SESSION_KEY = 'coreflux.session';

const memory: Record<string, string> = {};

async function read(key: string): Promise<string | null> {
  if (Platform.OS === 'web') return memory[key] ?? null;
  return SecureStore.getItemAsync(key);
}
async function write(key: string, value: string | null): Promise<void> {
  if (value === null) {
    if (Platform.OS === 'web') { delete memory[key]; return; }
    return SecureStore.deleteItemAsync(key);
  }
  if (Platform.OS === 'web') { memory[key] = value; return; }
  return SecureStore.setItemAsync(key, value);
}

export const getAccessToken  = () => read(ACCESS_KEY);
export const setAccessToken  = (v: string | null) => write(ACCESS_KEY, v);
export const getRefreshToken = () => read(REFRESH_KEY);
export const setRefreshToken = (v: string | null) => write(REFRESH_KEY, v);

export type SessionShape = {
  user: { id: number; name: string; email: string; role: string };
  tenant: { id: number; code: string; name: string };
};

export async function getSession(): Promise<SessionShape | null> {
  const raw = await read(SESSION_KEY);
  if (!raw) return null;
  try { return JSON.parse(raw); } catch { return null; }
}
export async function setSession(s: SessionShape | null): Promise<void> {
  return write(SESSION_KEY, s ? JSON.stringify(s) : null);
}

export async function clearAll(): Promise<void> {
  await write(ACCESS_KEY,  null);
  await write(REFRESH_KEY, null);
  await write(SESSION_KEY, null);
}
