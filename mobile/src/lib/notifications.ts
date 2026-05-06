/**
 * Push notifications + deep-link wiring.
 *
 * Sprint 6 goal: when an approver receives a "bill needs approval"
 * push, tapping the notification routes them straight to the
 * single-bill detail screen with 1-tap approve/reject buttons —
 * no inbox scan needed.
 *
 * The push payload comes from `core/push_service.php` via
 * `core/workflow_engine.php::_workflowPushApprovers`. The PHP side
 * always sets two fields:
 *
 *   • `data.deep_link`         — web-style path  (e.g. /modules/ap/bills/123)
 *   • `data.mobile_deep_link`  — `coreflux://approvals/<instance_id>`
 *
 * We prefer the mobile_deep_link; we fall back to interpreting any
 * `coreflux://` URL the platform delivers (foreground / background /
 * cold-start) so universal-link taps + push taps share one code path.
 */
import * as Notifications from 'expo-notifications';
import * as Linking from 'expo-linking';
import { router } from 'expo-router';
import { Platform } from 'react-native';
import { api } from '../api/client';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList:   true,
    shouldPlaySound:  true,
    shouldSetBadge:   true,
    shouldShowAlert:  true,
  }),
});

/**
 * Pull a `coreflux://approvals/<id>` (or the `mobile_deep_link` payload
 * field) out of an arbitrary notification / linking event and route to
 * the detail screen.
 *
 * Exported (not just internal) so the smoke test can exercise it.
 */
export function routeFromDeepLink(input: string | null | undefined): boolean {
  if (!input) return false;
  // Accept full URLs or bare paths like '/approvals/123'.
  const url = String(input);
  const m = url.match(/approvals\/(\d+)/);
  if (!m) return false;
  const id = Number(m[1]);
  if (!id || Number.isNaN(id)) return false;
  router.push(`/(tabs)/approvals/${id}` as never);
  return true;
}

function extractTarget(data: Record<string, unknown> | undefined | null): string | null {
  if (!data) return null;
  const mobile = data['mobile_deep_link'];
  if (typeof mobile === 'string' && mobile) return mobile;
  const web = data['deep_link'];
  if (typeof web === 'string' && web) return web;
  return null;
}

/**
 * Wire notification + universal-link handlers. Call once from the root
 * layout. Returns a teardown function for cleanup.
 */
export function registerDeepLinkHandlers(): () => void {
  // 1) App opened cold from a notification tap.
  Notifications.getLastNotificationResponseAsync().then((res) => {
    const target = extractTarget(res?.notification?.request?.content?.data as Record<string, unknown>);
    if (target) routeFromDeepLink(target);
  }).catch(() => {});

  // 2) Notification tapped while app is foreground/background.
  const respSub = Notifications.addNotificationResponseReceivedListener((res) => {
    const target = extractTarget(res?.notification?.request?.content?.data as Record<string, unknown>);
    if (target) routeFromDeepLink(target);
  });

  // 3) Universal link / `coreflux://` URL while app is running.
  const linkSub = Linking.addEventListener('url', ({ url }) => {
    if (!routeFromDeepLink(url)) {
      // Fall back to pulling the path off the URL.
      try {
        const parsed = Linking.parse(url);
        if (parsed.path) routeFromDeepLink(parsed.path);
      } catch {/* noop */}
    }
  });

  // 4) Cold-start universal link.
  Linking.getInitialURL().then((url) => {
    if (url) routeFromDeepLink(url) || routeFromDeepLink(Linking.parse(url).path);
  }).catch(() => {});

  return () => {
    respSub?.remove?.();
    linkSub?.remove?.();
  };
}

/**
 * Request notification permission + register the device with the
 * backend so `core/push_service.php` can target it. Best-effort —
 * never throws, never blocks the UI on a denied permission.
 */
export async function registerForPushAsync(): Promise<string | null> {
  try {
    const settings = await Notifications.getPermissionsAsync();
    let status = settings.status;
    if (status !== 'granted') {
      status = (await Notifications.requestPermissionsAsync()).status;
    }
    if (status !== 'granted') return null;

    const tokenResp = await Notifications.getDevicePushTokenAsync();
    const token = tokenResp?.data ?? null;
    if (!token) return null;

    const platform = Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web';
    await api('/api/auth/mobile_devices.php', {
      method: 'POST',
      body: {
        platform,
        apns_token: platform === 'ios'     ? token : null,
        fcm_token:  platform !== 'ios'     ? token : null,
      },
    }).catch(() => null);
    return String(token);
  } catch {
    return null;
  }
}
