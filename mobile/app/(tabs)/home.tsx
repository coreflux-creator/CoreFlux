import { useEffect, useState } from 'react';
import { View, Text, ScrollView, RefreshControl } from 'react-native';
import { listMyTimeEntries, type TimeEntry } from '@/lib/api';
import { getCurrentSession, type SessionShape } from '@/api/storage';

function weekRange(): { from: string; to: string } {
  const d = new Date();
  const day = (d.getDay() + 6) % 7;          // Mon = 0
  const monday = new Date(d); monday.setDate(d.getDate() - day);
  const sunday = new Date(monday); sunday.setDate(monday.getDate() + 6);
  const fmt = (x: Date) => x.toISOString().slice(0, 10);
  return { from: fmt(monday), to: fmt(sunday) };
}

export default function HomeScreen() {
  const [session, setSession] = useState<SessionShape | null>(null);
  const [entries, setEntries] = useState<TimeEntry[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setBusy(true); setError(null);
    try {
      const s = await getCurrentSession(); setSession(s);
      const { from, to } = weekRange();
      const r = await listMyTimeEntries(from, to);
      setEntries(r.entries ?? []);
    } catch (e: unknown) {
      setError((e as Error)?.message ?? 'Failed to load');
    } finally { setBusy(false); }
  }
  useEffect(() => { load(); }, []);

  const total    = entries.reduce((s, e) => s + Number(e.hours || 0), 0);
  const billable = entries.filter(e => e.category.endsWith('_billable')).reduce((s, e) => s + Number(e.hours || 0), 0);
  const ot       = entries.filter(e => e.category.startsWith('OT')).reduce((s, e) => s + Number(e.hours || 0), 0);
  const pending  = entries.filter(e => e.status === 'pending_review').length;
  const draft    = entries.filter(e => e.status === 'draft').length;

  return (
    <ScrollView
      testID="home-scroll"
      contentContainerStyle={{ padding: 20 }}
      refreshControl={<RefreshControl refreshing={busy} onRefresh={load} />}
    >
      <Text style={{ color: '#94a3b8', fontSize: 12, textTransform: 'uppercase', letterSpacing: 0.5 }}>
        {session?.tenant?.name ?? 'CoreFlux'}
      </Text>
      <Text style={{ fontSize: 24, fontWeight: '700', marginTop: 4, marginBottom: 24 }}>
        Hi {session?.user?.name?.split(' ')[0] ?? ''}
      </Text>

      <Text style={hdr}>This week</Text>
      <View style={grid}>
        <Tile label="Total hours"     value={total.toFixed(2)} />
        <Tile label="Billable hours"  value={billable.toFixed(2)} />
        <Tile label="OT hours"        value={ot.toFixed(2)} />
        <Tile label="Pending review"  value={String(pending)} />
        <Tile label="Drafts"          value={String(draft)} />
      </View>

      {error && <Text style={{ color: '#ef4444', marginTop: 12 }} testID="home-error">{error}</Text>}
      {!error && entries.length === 0 && !busy && (
        <Text style={{ color: '#64748b', marginTop: 24 }}>No entries this week. Tap Time to add hours.</Text>
      )}
    </ScrollView>
  );
}

function Tile({ label, value }: { label: string; value: string }) {
  return (
    <View style={tile} testID={`home-tile-${label}`}>
      <Text style={{ color: '#64748b', fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</Text>
      <Text style={{ fontSize: 22, fontWeight: '700', marginTop: 4 }}>{value}</Text>
    </View>
  );
}

const hdr  = { fontSize: 14, fontWeight: '600' as const, marginBottom: 12, color: '#475569', textTransform: 'uppercase' as const, letterSpacing: 0.5 };
const grid = { flexDirection: 'row' as const, flexWrap: 'wrap' as const, gap: 8 };
const tile = {
  flexBasis: '48%' as const,
  borderWidth: 1, borderColor: '#e2e8f0', borderRadius: 8, padding: 14, backgroundColor: '#fff',
};
