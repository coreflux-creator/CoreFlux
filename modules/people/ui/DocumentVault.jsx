import React from 'react';

/**
 * Document Vault — cross-person document browser.
 *
 * Phase A scope: placeholder + per-person documents browse via search.
 * Full cross-tenant doc index is Phase B (needs aggregator endpoint
 * not in MVP cut). For now this points users at per-person Documents tab.
 */
export default function DocumentVault() {
  return (
    <section data-testid="document-vault">
      <h2>Document Vault</h2>
      <p style={{ color: '#666' }}>
        Browse and manage documents per person. Open any person from the
        Directory and use the <strong>Documents</strong> tab.
      </p>
      <p style={{ color: '#888' }}>
        (Cross-person aggregated vault view ships in Phase B; the storage
        layer and per-person endpoints are already wired to AWS S3 via
        Core StorageService.)
      </p>
    </section>
  );
}
