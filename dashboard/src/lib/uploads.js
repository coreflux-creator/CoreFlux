import { api } from '../lib/api';

/**
 * Browser-side helper for the presigned-S3-POST upload flow used across
 * AP, Billing, People, Placements.
 *
 * Steps:
 *   1. GET upload_url endpoint → {storage_key, upload: {url, fields}}
 *   2. POST FormData(fields + file) directly to S3
 *   3. Caller posts {storage_key, filename, mime, size_bytes} back to the
 *      module's "register" endpoint (e.g. /api/ap/bills?action=attach)
 *
 * @param {string} uploadUrlEndpoint  e.g. '/modules/ap/api/bills.php?action=upload_url&id=42&file_name=invoice.pdf'
 * @param {File}   file
 * @returns {Promise<{storage_key, filename, mime, size_bytes}>}
 */
export async function uploadFileViaPresignedPost(uploadUrlEndpoint, file) {
  const meta = await api.get(uploadUrlEndpoint);
  if (!meta?.storage_key || !meta?.upload?.url) throw new Error('upload_url endpoint returned malformed response');

  const form = new FormData();
  Object.entries(meta.upload.fields || {}).forEach(([k, v]) => form.append(k, v));
  form.append('file', file);

  const r = await fetch(meta.upload.url, { method: 'POST', body: form });
  if (!r.ok && r.status !== 204) {
    const txt = await r.text().catch(() => '');
    throw new Error(`S3 upload failed (${r.status}): ${txt.slice(0, 200)}`);
  }
  return {
    storage_key: meta.storage_key,
    filename:    file.name,
    mime:        file.type || null,
    size_bytes:  file.size  || null,
  };
}
