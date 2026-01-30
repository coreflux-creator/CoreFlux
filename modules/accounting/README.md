# CoreFlux Accounting Module

> General ledger, accounts payable, accounts receivable, and financial reporting for CoreFlux.

## Overview

The Accounting module provides comprehensive financial management capabilities including:

- **Chart of Accounts** - Organize your accounts by type (Assets, Liabilities, Equity, Revenue, Expenses)
- **Journal Entries** - Record transactions with full audit trail
- **General Ledger** - View account activity and balances
- **Accounts Payable** - Manage vendor invoices and payments
- **Accounts Receivable** - Track customer invoices and receipts
- **Financial Reports** - Generate Balance Sheet, Income Statement, Trial Balance

## Installation

This module is designed to be installed as a Git submodule in the CoreFlux core repository.

### As a Submodule (Recommended)

```bash
# In the main CoreFlux repo
git submodule add https://github.com/yourorg/coreflux-accounting.git modules/accounting
git commit -m "Add accounting module"
```

### Standalone (For Development)

```bash
git clone https://github.com/yourorg/coreflux-accounting.git
cd coreflux-accounting
# Symlink to your CoreFlux installation
ln -s /path/to/coreflux/core ../core
```

## Database Setup

Run the migration to create the required tables:

```bash
mysql -u user -p database < migrations/001_initial_schema.sql
```

Or execute via PHP:
```php
require_once 'migrations/migrate.php';
runAccountingMigrations($pdo);
```

## Configuration

1. **Enable Module**: In Master Admin → Modules, enable "Accounting" for your tenant
2. **Assign Permissions**: Assign accounting permissions to roles
3. **Configure Fiscal Year**: Set your fiscal year start date in module settings

## Permissions

| Permission | Description |
|------------|-------------|
| `accounting.view` | Access Accounting module |
| `accounting.coa.view` | View Chart of Accounts |
| `accounting.coa.edit` | Create/edit accounts |
| `accounting.journal.view` | View journal entries |
| `accounting.journal.create` | Create journal entries |
| `accounting.journal.post` | Post journal entries |
| `accounting.journal.reverse` | Reverse posted entries |
| `accounting.reports.view` | View financial reports |
| `accounting.reports.export` | Export reports to CSV/PDF |

## File Structure

```
accounting/
├── manifest.php           # Module metadata
├── views/
│   ├── overview.php       # Dashboard/landing
│   ├── chart_of_accounts.php
│   ├── journal_entries.php
│   ├── journal_new.php
│   ├── general_ledger.php
│   ├── accounts_payable.php
│   ├── accounts_receivable.php
│   ├── reports.php
│   └── settings.php
├── api/
│   ├── accounts.php       # CoA endpoints
│   ├── journal.php        # Journal entry endpoints
│   └── reports.php        # Report generation
├── includes/
│   ├── functions.php      # Helper functions
│   └── validation.php     # Input validation
├── migrations/
│   └── 001_initial_schema.sql
└── README.md
```

## Core Integration

This module uses CoreFlux core services:

```php
// Authentication (handled by dashboard.php)
global $user, $tenant, $tenantId;

// UI Components
require_once __DIR__ . '/../../../core/components/ui.php';
cfPageHero($config);
cfCard(['title' => 'Accounts'], function() { ... });

// Database
require_once __DIR__ . '/../../../core/db.php';
$pdo = getDbConnection();
```

## Version History

- **1.0.0** - Initial release
  - Chart of Accounts management
  - Journal entry creation and posting
  - Basic financial reports

## License

Proprietary - CoreFlux
