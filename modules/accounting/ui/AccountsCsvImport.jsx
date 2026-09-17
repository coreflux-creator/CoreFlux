import React from 'react';
import { Download } from 'lucide-react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

export default function AccountsCsvImport() {
  return (
    <div data-testid="accounting-accounts-csv-workspace">
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
        <a
          className="btn btn--ghost"
          href="/modules/accounting/api/export.php?type=coa"
          data-testid="accounting-accounts-csv-export"
        >
          <Download size={15} aria-hidden="true" />
          Export current accounts
        </a>
      </div>
      <CsvImportPage
        endpoint="/modules/accounting/api/accounts_csv_import.php"
        entityLabel="Chart of accounts"
        description="Export the current chart, complete or correct it in a spreadsheet, then upload it here. Account ID is the safest update key; account code is used when the ID is blank. Posted accounts keep their accounting type and normal balance protected."
        previewColumns={[
          { key: 'account_id', label: 'ID' },
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Name' },
          { key: 'account_type', label: 'Type' },
          { key: 'parent_account_code', label: 'Parent' },
          { key: 'active', label: 'Active' },
        ]}
        backTo=".."
        backLabel="Back to chart of accounts"
        testidPrefix="accounting-accounts-csv-import"
        presetEntity="accounting_accounts"
        updateExistingLabel="Update matching accounts by Account ID or code"
      />
    </div>
  );
}
