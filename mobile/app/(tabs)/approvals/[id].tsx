import { useEffect, useState } from 'react';
import { View, Text, ScrollView, Pressable, ActivityIndicator, Alert } from 'react-native';
import { useLocalSearchParams, router } from 'expo-router';
import { workflowGetInstance, workflowAct, type WorkflowInstance } from '@/lib/api';

/**
 * Single workflow-instance detail with 1-tap approve / reject.
 *
 * Reachable two ways:
 *   1) Tap a row in the approvals inbox.
 *   2) Tap a push notification that carries
 *      `data.deep_link = "coreflux://approvals/<instanceId>"` — the
 *      notification response handler in `app/_layout.tsx` calls
 *      `router.push('/(tabs)/approvals/<id>')` and lands here.
 *
 * Goal per Sprint 6 scope: from a "bill needs approval" push, the
 * approver is one tap away from the bill summary and one more tap
 * away from approve/reject.
 */
export default function ApprovalDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const instanceId = Number(id);
  const [instance, setInstance] = useState<WorkflowInstance | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [acting, setActing] = useState<null | 'approve' | 'reject'>(null);

  async function load() {
    if (!instanceId) { setError('Missing approval id'); setBusy(false); return; }
    setBusy(true); setError(null);
    try {
      const r = await workflowGetInstance(instanceId);
      setInstance(r.instance ?? null);
    } catch (e: unknown) {
      setError((e as Error).message);
    } finally { setBusy(false); }
  }
  useEffect(() => { load(); }, [instanceId]);

  async function act(action: 'approve' | 'reject') {
    if (!instanceId) return;
    setActing(action);
    try {
      await workflowAct(instanceId, action);
      // Pop back to inbox so the user sees the row vanish from their queue.
      router.replace('/(tabs)/approvals');
    } catch (e: unknown) {
      Alert.alert(`${action} failed`, (e as Error).message);
    } finally {
      setActing(null);
    }
  }

  if (busy) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }} testID="approval-detail-loading">
        <ActivityIndicator />
      </View>
    );
  }
  if (error || !instance) {
    return (
      <View style={{ padding: 24 }}>
        <Text style={{ color: '#ef4444' }} testID="approval-detail-error">{error ?? 'Not found'}</Text>
        <Pressable onPress={() => router.replace('/(tabs)/approvals')} style={{ marginTop: 16 }}>
          <Text style={{ color: '#2563eb', fontWeight: '600' }}>Back to inbox</Text>
        </Pressable>
      </View>
    );
  }

  const payload = (instance.payload ?? {}) as { body?: string; amount_label?: string; risk?: string };
  const isPending = instance.status === 'pending';

  return (
    <ScrollView contentContainerStyle={{ padding: 20 }} testID={`approval-detail-${instance.id}`}>
      <Text style={{ color: '#64748b', fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.6 }}>
        {instance.subject_type} · #{instance.subject_id}
      </Text>
      <Text style={{ fontSize: 22, fontWeight: '700', marginTop: 6, color: '#0f172a' }}>{instance.label}</Text>

      {!!payload.amount_label && (
        <Text style={{ marginTop: 10, fontSize: 18, color: '#0f172a', fontWeight: '600' }}>{payload.amount_label}</Text>
      )}
      {!!payload.risk && (
        <Text style={{ marginTop: 4, color: payload.risk === 'high' ? '#dc2626' : '#0f172a' }}>
          Risk: {payload.risk}
        </Text>
      )}
      {!!payload.body && (
        <Text style={{ marginTop: 12, color: '#334155', lineHeight: 20 }}>{payload.body}</Text>
      )}

      <View style={{ marginTop: 18, padding: 12, backgroundColor: '#f1f5f9', borderRadius: 8 }}>
        <Text style={{ color: '#64748b', fontSize: 12 }}>Status</Text>
        <Text style={{ fontWeight: '600', color: '#0f172a', marginTop: 2 }} testID="approval-detail-status">
          {instance.status} · step {instance.current_step}
        </Text>
        {instance.sla_due_at && (
          <Text style={{ color: '#dc2626', fontSize: 12, marginTop: 6 }}>Due {instance.sla_due_at}</Text>
        )}
      </View>

      {isPending ? (
        <View style={{ flexDirection: 'row', gap: 12, marginTop: 24 }}>
          <Pressable
            disabled={!!acting}
            onPress={() => act('reject')}
            style={[bigBtn, dangerBig, !!acting && { opacity: 0.5 }]}
            testID="approval-detail-reject"
          >
            <Text style={{ color: '#dc2626', fontWeight: '700', fontSize: 16 }}>
              {acting === 'reject' ? 'Rejecting…' : 'Reject'}
            </Text>
          </Pressable>
          <Pressable
            disabled={!!acting}
            onPress={() => act('approve')}
            style={[bigBtn, primaryBig, !!acting && { opacity: 0.5 }]}
            testID="approval-detail-approve"
          >
            <Text style={{ color: '#fff', fontWeight: '700', fontSize: 16 }}>
              {acting === 'approve' ? 'Approving…' : 'Approve'}
            </Text>
          </Pressable>
        </View>
      ) : (
        <Text style={{ marginTop: 24, color: '#64748b' }}>This approval is already {instance.status}.</Text>
      )}
    </ScrollView>
  );
}

const bigBtn    = { flex: 1, paddingVertical: 16, borderRadius: 10, alignItems: 'center' as const };
const primaryBig = { backgroundColor: '#2563eb' };
const dangerBig  = { backgroundColor: '#fff', borderWidth: 1, borderColor: '#fecaca' };
