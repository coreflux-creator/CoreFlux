import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../modules/ap/ui/directBillAccounts.js', import.meta.url), 'utf8');
const { generatedBillAccountChoices } = await import(
  `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
);

test('generated AP offers staffing cost accounts, not payroll or operating expense', () => {
  const account = (code, subtype = 'cogs', isSystem = 1, active = 1) => ({
    code, account_type: 'expense', subtype, is_system_account: isSystem,
    active, is_postable: 1,
  });
  const rows = [
    account('5000'), account('5010'), account('5020'), account('5070'),
    account('5080'), account('5095', 'cogs', 0), account('6990', 'operating_expense'),
    account('5096', 'cogs', 0, 0),
  ];
  assert.deepEqual(generatedBillAccountChoices(rows).map(row => row.code),
    ['5010', '5070', '5080', '5095']);
});
