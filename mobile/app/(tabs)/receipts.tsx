import { useState } from 'react';
import { View, Text, Pressable, Image, Alert, ActivityIndicator } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { API_BASE } from '@/api/client';
import * as Storage from '@/api/storage';

/**
 * Capture a receipt via camera or gallery, upload it to the AP receipts
 * endpoint as multipart/form-data. The backend will OCR + extract via
 * the existing AI pipeline (W-9 / receipts share the same OCR plumbing).
 */
export default function ReceiptsScreen() {
  const [uri, setUri] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function pick(source: 'camera' | 'library') {
    const perm = source === 'camera'
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) { Alert.alert('Permission required'); return; }
    const r = source === 'camera'
      ? await ImagePicker.launchCameraAsync({ quality: 0.7, base64: false })
      : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ImagePicker.MediaTypeOptions.Images, quality: 0.7 });
    if (r.canceled) return;
    setUri(r.assets[0].uri);
  }

  async function upload() {
    if (!uri) return;
    setBusy(true);
    try {
      const access = await Storage.getAccessToken();
      const fd = new FormData();
      fd.append('file', {
        uri,
        name: `receipt-${Date.now()}.jpg`,
        type: 'image/jpeg',
        // @ts-expect-error — RN FormData accepts a file-shaped object.
      });
      fd.append('source', 'mobile_receipt');
      const r = await fetch(`${API_BASE}/api/ap/receipts/upload`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${access ?? ''}` },
        body: fd,
      });
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      Alert.alert('Receipt uploaded', 'AI is extracting details. You will be notified when ready.');
      setUri(null);
    } catch (e: unknown) {
      Alert.alert('Upload failed', (e as Error).message);
    } finally { setBusy(false); }
  }

  return (
    <View style={{ flex: 1, padding: 20 }} testID="receipts-screen">
      <Text style={{ fontSize: 16, fontWeight: '600', marginBottom: 4 }}>Capture a receipt</Text>
      <Text style={{ color: '#64748b', marginBottom: 24 }}>
        We'll attach it to your next AP submission and extract details automatically.
      </Text>

      <View style={{ flexDirection: 'row', gap: 8 }}>
        <Pressable testID="receipts-camera"  onPress={() => pick('camera')}  style={[btn, primary]}>
          <Text style={{ color: '#fff', fontWeight: '600' }}>Camera</Text>
        </Pressable>
        <Pressable testID="receipts-library" onPress={() => pick('library')} style={[btn, ghost]}>
          <Text style={{ color: '#0f172a', fontWeight: '600' }}>Photo library</Text>
        </Pressable>
      </View>

      {uri && (
        <View style={{ marginTop: 24 }}>
          <Image source={{ uri }} style={{ width: '100%', aspectRatio: 1, borderRadius: 8 }} resizeMode="cover" />
          <Pressable testID="receipts-upload" disabled={busy} onPress={upload} style={[btn, primary, { marginTop: 12 }]}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={{ color: '#fff', fontWeight: '600' }}>Upload</Text>}
          </Pressable>
        </View>
      )}
    </View>
  );
}

const btn = { flex: 1, padding: 14, borderRadius: 10, alignItems: 'center' as const };
const primary = { backgroundColor: '#2563eb' };
const ghost = { backgroundColor: '#fff', borderWidth: 1, borderColor: '#cbd5e1' };
