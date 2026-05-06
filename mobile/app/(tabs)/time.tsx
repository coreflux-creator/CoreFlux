import { useEffect, useState } from 'react';
import { View, Text, TextInput, Pressable, ScrollView, Alert, ActivityIndicator } from 'react-native';
import { listMyPlacements, createTimeEntry, submitTimeEntry, TIME_CATEGORIES, type Placement, type TimeCategory } from '@/lib/api';

export default function TimeEntryScreen() {
  const [placements, setPlacements] = useState<Placement[]>([]);
  const [placementId, setPlacementId] = useState<number | null>(null);
  const [workDate, setWorkDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [category, setCategory] = useState<TimeCategory>('regular_billable');
  const [hours, setHours] = useState('8');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const r = await listMyPlacements();
        setPlacements(r.placements ?? []);
        if (r.placements?.[0]) setPlacementId(r.placements[0].id);
      } catch (e: unknown) {
        Alert.alert('Could not load placements', (e as Error).message);
      }
    })();
  }, []);

  async function onSave(submit: boolean) {
    if (!placementId) { Alert.alert('Pick a placement'); return; }
    const h = Number(hours);
    if (!Number.isFinite(h) || h <= 0 || h > 24) { Alert.alert('Hours must be > 0 and ≤ 24'); return; }
    setBusy(true);
    try {
      const entry = await createTimeEntry({
        placement_id: placementId, work_date: workDate, category, hours: h, description: description || undefined,
      });
      if (submit) await submitTimeEntry((entry as { id: number }).id);
      Alert.alert('Saved', submit ? 'Submitted for approval.' : 'Saved as draft.');
      setHours('8'); setDescription('');
    } catch (e: unknown) {
      Alert.alert('Save failed', (e as Error).message);
    } finally { setBusy(false); }
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 20 }} testID="time-entry-form">
      <Text style={lbl}>Placement</Text>
      <View style={{ borderWidth: 1, borderColor: '#e2e8f0', borderRadius: 8 }}>
        {placements.map(p => (
          <Pressable
            key={p.id}
            testID={`time-placement-${p.id}`}
            onPress={() => setPlacementId(p.id)}
            style={{ padding: 12, borderBottomWidth: 1, borderBottomColor: '#f1f5f9', backgroundColor: placementId === p.id ? '#eff6ff' : '#fff' }}
          >
            <Text style={{ fontWeight: '600' }}>{p.title || `Placement #${p.id}`}</Text>
            {!!p.end_client_name && <Text style={{ color: '#64748b', fontSize: 12 }}>{p.end_client_name}</Text>}
          </Pressable>
        ))}
        {placements.length === 0 && (
          <Text testID="time-no-placements" style={{ color: '#64748b', padding: 14 }}>No active placements.</Text>
        )}
      </View>

      <Text style={lbl}>Work date</Text>
      <TextInput testID="time-work-date" value={workDate} onChangeText={setWorkDate} placeholder="YYYY-MM-DD" style={fld} />

      <Text style={lbl}>Category</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {TIME_CATEGORIES.map(c => (
          <Pressable
            key={c.value}
            testID={`time-cat-${c.value}`}
            onPress={() => setCategory(c.value)}
            style={{
              paddingVertical: 6, paddingHorizontal: 12, borderRadius: 16, borderWidth: 1,
              borderColor: category === c.value ? '#2563eb' : '#cbd5e1',
              backgroundColor: category === c.value ? '#dbeafe' : '#fff',
            }}
          >
            <Text style={{ color: category === c.value ? '#1d4ed8' : '#334155', fontSize: 12 }}>{c.label}</Text>
          </Pressable>
        ))}
      </View>

      <Text style={lbl}>Hours</Text>
      <TextInput testID="time-hours" value={hours} onChangeText={setHours} keyboardType="decimal-pad" style={fld} />

      <Text style={lbl}>Notes (optional)</Text>
      <TextInput
        testID="time-notes"
        value={description}
        onChangeText={setDescription}
        placeholder="What did you work on?"
        multiline
        style={[fld, { minHeight: 80, textAlignVertical: 'top' }]}
      />

      <View style={{ flexDirection: 'row', gap: 8, marginTop: 24 }}>
        <Pressable testID="time-save-draft" disabled={busy} onPress={() => onSave(false)} style={[btn, ghost]}>
          <Text style={{ color: '#0f172a', fontWeight: '600' }}>Save draft</Text>
        </Pressable>
        <Pressable testID="time-submit" disabled={busy} onPress={() => onSave(true)} style={[btn, solid]}>
          {busy ? <ActivityIndicator color="#fff" /> : <Text style={{ color: '#fff', fontWeight: '600' }}>Submit</Text>}
        </Pressable>
      </View>
    </ScrollView>
  );
}

const lbl = { fontSize: 12, color: '#475569', marginTop: 16, marginBottom: 6, fontWeight: '600' as const };
const fld = { borderWidth: 1, borderColor: '#e2e8f0', borderRadius: 8, padding: 12, fontSize: 15, backgroundColor: '#fff' };
const btn = { flex: 1, padding: 14, borderRadius: 10, alignItems: 'center' as const };
const solid = { backgroundColor: '#2563eb' };
const ghost = { backgroundColor: '#fff', borderWidth: 1, borderColor: '#cbd5e1' };
