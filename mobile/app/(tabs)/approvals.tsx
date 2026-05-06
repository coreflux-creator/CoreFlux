import { useEffect, useState } from 'react';
import { View, Text, ScrollView, Pressable, RefreshControl, Alert } from 'react-native';
import { workflowInbox, workflowAct, type WorkflowInstance } from '@/lib/api';

/**
 * Approvals inbox — pulls pending workflow_instances assigned to the
 * current user (via /api/workflow/inbox). Approve / reject inline.
 */
export default function ApprovalsScreen() {
  const [items, setItems] = useState<WorkflowInstance[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setBusy(true); setError(null);
    try {
      const r = await workflowInbox();
      setItems(r.instances ?? []);
    } catch (e: unknown) {
      setError((e as Error).message);
    } finally { setBusy(false); }
  }
  useEffect(() => { load(); }, []);

  async function act(id: number, action: 'approve' | 'reject') {
    try {
      await workflowAct(id, action);
      await load();
    } catch (e: unknown) {
      Alert.alert(`${action} failed`, (e as Error).message);
    }
  }

  return (
    <ScrollView
      testID="approvals-scroll"
      refreshControl={<RefreshControl refreshing={busy} onRefresh={load} />}
      contentContainerStyle={{ padding: 16 }}
    >
      {error && <Text style={{ color: '#ef4444' }} testID="approvals-error">{error}</Text>}
      {items.length === 0 && !busy && !error && (
        <Text style={{ color: '#64748b', padding: 16 }}>No pending approvals 🎉</Text>
      )}
      {items.map(i => (
        <View key={i.id} style={card} testID={`approvals-item-${i.id}`}>
          <Text style={{ color: '#64748b', fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.4 }}>{i.subject_type}</Text>
          <Text style={{ fontSize: 16, fontWeight: '600', marginTop: 4 }}>{i.label}</Text>
          {!!(i.payload as { body?: string })?.body && (
            <Text style={{ color: '#334155', marginTop: 6 }}>{(i.payload as { body?: string }).body}</Text>
          )}
          {i.sla_due_at && (
            <Text style={{ color: '#dc2626', fontSize: 12, marginTop: 6 }}>Due {i.sla_due_at}</Text>
          )}
          <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
            <Pressable onPress={() => act(i.id, 'reject')}  style={[btn, danger]} testID={`approvals-reject-${i.id}`}>
              <Text style={{ color: '#dc2626', fontWeight: '600' }}>Reject</Text>
            </Pressable>
            <Pressable onPress={() => act(i.id, 'approve')} style={[btn, primary]} testID={`approvals-approve-${i.id}`}>
              <Text style={{ color: '#fff',    fontWeight: '600' }}>Approve</Text>
            </Pressable>
          </View>
        </View>
      ))}
    </ScrollView>
  );
}

const card = {
  backgroundColor: '#fff',
  borderWidth: 1, borderColor: '#e2e8f0', borderRadius: 10,
  padding: 16, marginBottom: 12,
};
const btn = { flex: 1, paddingVertical: 10, borderRadius: 8, alignItems: 'center' as const };
const primary = { backgroundColor: '#2563eb' };
const danger  = { backgroundColor: '#fff', borderWidth: 1, borderColor: '#fecaca' };
