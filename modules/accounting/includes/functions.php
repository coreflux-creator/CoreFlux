<?php
/**
 * Accounting Module - Helper Functions
 */

/**
 * Get the database connection
 */
function acctGetDb() {
    require_once __DIR__ . '/../../core/db.php';
    return getDbConnection();
}

/**
 * Generate next journal entry number for a tenant
 */
function acctGenerateEntryNumber($tenantId) {
    $pdo = acctGetDb();
    $year = date('Y');
    
    // Get the last entry number for this year
    $stmt = $pdo->prepare("
        SELECT entry_number FROM acct_journal_entries 
        WHERE tenant_id = ? AND entry_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$tenantId, "JE-{$year}-%"]);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = (int)substr($last, -5) + 1;
    } else {
        $num = 1;
    }
    
    return sprintf("JE-%s-%05d", $year, $num);
}

/**
 * Generate next invoice number for AR
 */
function acctGenerateInvoiceNumber($tenantId) {
    $pdo = acctGetDb();
    $year = date('Y');
    
    $stmt = $pdo->prepare("
        SELECT invoice_number FROM acct_ar_invoices 
        WHERE tenant_id = ? AND invoice_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$tenantId, "INV-{$year}-%"]);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = (int)substr($last, -5) + 1;
    } else {
        $num = 1;
    }
    
    return sprintf("INV-%s-%05d", $year, $num);
}

/**
 * Get accounts for a tenant, optionally filtered by type
 */
function acctGetAccounts($tenantId, $type = null, $activeOnly = true) {
    $pdo = acctGetDb();
    
    $sql = "SELECT * FROM acct_accounts WHERE tenant_id = ?";
    $params = [$tenantId];
    
    if ($type) {
        $sql .= " AND account_type = ?";
        $params[] = $type;
    }
    
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    
    $sql .= " ORDER BY account_number ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get account by ID
 */
function acctGetAccount($accountId) {
    $pdo = acctGetDb();
    $stmt = $pdo->prepare("SELECT * FROM acct_accounts WHERE id = ?");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get journal entries for a tenant
 */
function acctGetJournalEntries($tenantId, $status = null, $limit = 50) {
    $pdo = acctGetDb();
    
    $sql = "SELECT * FROM acct_journal_entries WHERE tenant_id = ?";
    $params = [$tenantId];
    
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY entry_date DESC, id DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get journal entry with lines
 */
function acctGetJournalEntry($entryId) {
    $pdo = acctGetDb();
    
    $stmt = $pdo->prepare("SELECT * FROM acct_journal_entries WHERE id = ?");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($entry) {
        $stmt = $pdo->prepare("
            SELECT jl.*, a.account_number, a.name as account_name 
            FROM acct_journal_lines jl
            JOIN acct_accounts a ON jl.account_id = a.id
            WHERE jl.journal_entry_id = ?
            ORDER BY jl.line_order ASC
        ");
        $stmt->execute([$entryId]);
        $entry['lines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $entry;
}

/**
 * Create a journal entry
 */
function acctCreateJournalEntry($tenantId, $data, $lines, $userId) {
    $pdo = acctGetDb();
    
    try {
        $pdo->beginTransaction();
        
        // Validate lines balance
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $line) {
            $totalDebit += floatval($line['debit_amount'] ?? 0);
            $totalCredit += floatval($line['credit_amount'] ?? 0);
        }
        
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new Exception("Journal entry must balance. Debits: $totalDebit, Credits: $totalCredit");
        }
        
        // Generate entry number
        $entryNumber = acctGenerateEntryNumber($tenantId);
        
        // Insert header
        $stmt = $pdo->prepare("
            INSERT INTO acct_journal_entries 
            (tenant_id, entry_number, entry_date, entry_type, description, memo, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
        ");
        $stmt->execute([
            $tenantId,
            $entryNumber,
            $data['entry_date'],
            $data['entry_type'] ?? 'standard',
            $data['description'] ?? '',
            $data['memo'] ?? '',
            $userId
        ]);
        
        $entryId = $pdo->lastInsertId();
        
        // Insert lines
        $lineStmt = $pdo->prepare("
            INSERT INTO acct_journal_lines 
            (journal_entry_id, account_id, debit_amount, credit_amount, description, line_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($lines as $i => $line) {
            $lineStmt->execute([
                $entryId,
                $line['account_id'],
                $line['debit_amount'] ?? 0,
                $line['credit_amount'] ?? 0,
                $line['description'] ?? '',
                $i
            ]);
        }
        
        $pdo->commit();
        return ['success' => true, 'entry_id' => $entryId, 'entry_number' => $entryNumber];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Post a journal entry
 */
function acctPostJournalEntry($entryId, $userId) {
    $pdo = acctGetDb();
    
    $entry = acctGetJournalEntry($entryId);
    if (!$entry) {
        return ['success' => false, 'error' => 'Entry not found'];
    }
    
    if ($entry['status'] !== 'draft') {
        return ['success' => false, 'error' => 'Only draft entries can be posted'];
    }
    
    $stmt = $pdo->prepare("
        UPDATE acct_journal_entries 
        SET status = 'posted', posted_at = NOW(), posted_by = ?
        WHERE id = ?
    ");
    $stmt->execute([$userId, $entryId]);
    
    // TODO: Update account balances
    
    return ['success' => true];
}

/**
 * Get trial balance for a tenant
 */
function acctGetTrialBalance($tenantId, $asOfDate = null) {
    $pdo = acctGetDb();
    
    if (!$asOfDate) {
        $asOfDate = date('Y-m-d');
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.account_number,
            a.name,
            a.account_type,
            a.normal_balance,
            COALESCE(SUM(jl.debit_amount), 0) as total_debits,
            COALESCE(SUM(jl.credit_amount), 0) as total_credits
        FROM acct_accounts a
        LEFT JOIN acct_journal_lines jl ON a.id = jl.account_id
        LEFT JOIN acct_journal_entries je ON jl.journal_entry_id = je.id 
            AND je.status = 'posted' 
            AND je.entry_date <= ?
        WHERE a.tenant_id = ? AND a.is_active = 1
        GROUP BY a.id
        ORDER BY a.account_number ASC
    ");
    $stmt->execute([$asOfDate, $tenantId]);
    
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate balances
    foreach ($accounts as &$account) {
        $debits = floatval($account['total_debits']);
        $credits = floatval($account['total_credits']);
        
        if ($account['normal_balance'] === 'debit') {
            $account['balance'] = $debits - $credits;
        } else {
            $account['balance'] = $credits - $debits;
        }
        
        $account['debit_balance'] = $account['balance'] > 0 && $account['normal_balance'] === 'debit' ? $account['balance'] : 0;
        $account['credit_balance'] = $account['balance'] > 0 && $account['normal_balance'] === 'credit' ? $account['balance'] : 0;
        
        if ($account['balance'] < 0) {
            // Contra balance
            if ($account['normal_balance'] === 'debit') {
                $account['credit_balance'] = abs($account['balance']);
            } else {
                $account['debit_balance'] = abs($account['balance']);
            }
        }
    }
    
    return $accounts;
}

/**
 * Format currency
 */
function acctFormatCurrency($amount, $currency = 'USD') {
    return '$' . number_format(floatval($amount), 2);
}
