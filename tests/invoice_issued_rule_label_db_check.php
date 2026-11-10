<?php
/** Legacy invoice-rule label repair on a disposable clean-install database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/posting_engine/seed_defaults.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('CI tenant missing.');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$oldName = 'AR invoice sent — passthrough';
$newName = 'AR invoice issued — passthrough';

$pdo->beginTransaction();
try {
    postingRulesSeedDefaults($tenantId);
    $find = $pdo->prepare(
        'SELECT r.id AS rule_id, r.description, t.id AS template_id
           FROM accounting_posting_rules r
           JOIN accounting_journal_templates t ON t.tenant_id = r.tenant_id
             AND t.id = r.journal_template_id
          WHERE r.tenant_id = :tenant AND r.event_type = "ar.invoice.issued"
            AND r.name = :rule_name AND t.name = :template_name
          ORDER BY r.id LIMIT 1'
    );
    $find->execute(['tenant' => $tenantId, 'rule_name' => $newName, 'template_name' => $newName]);
    $default = $find->fetch(PDO::FETCH_ASSOC);
    $assert((bool) $default && $default['description'] === 'Default seed pack — Sprint 7c.1. Customisable.',
        'fresh tenant has correctly named invoice-issuance default');

    $pdo->prepare('UPDATE accounting_posting_rules SET name = :name WHERE id = :id AND tenant_id = :tenant')
        ->execute(['name' => $oldName, 'id' => $default['rule_id'], 'tenant' => $tenantId]);
    $pdo->prepare('UPDATE accounting_journal_templates SET name = :name WHERE id = :id AND tenant_id = :tenant')
        ->execute(['name' => $oldName, 'id' => $default['template_id'], 'tenant' => $tenantId]);

    $pdo->prepare('INSERT INTO accounting_journal_templates
        (tenant_id, name, memo_template, currency_source, line_source)
        VALUES (:tenant, :name, "Tenant-specific memo", "payload", "payload")')
        ->execute(['tenant' => $tenantId, 'name' => $oldName]);
    $customTemplateId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO accounting_posting_rules
        (tenant_id, name, event_type, journal_template_id, priority, status, description)
        VALUES (:tenant, :name, "ar.invoice.issued", :template, 50, "active", "Tenant customized rule")')
        ->execute(['tenant' => $tenantId, 'name' => $oldName, 'template' => $customTemplateId]);
    $customRuleId = (int) $pdo->lastInsertId();

    $migration = file_get_contents(__DIR__ . '/../modules/accounting/migrations/040_invoice_issued_rule_label.sql');
    if ($migration === false) throw new RuntimeException('Migration missing.');
    $pdo->exec($migration);

    $ruleStmt = $pdo->prepare('SELECT name FROM accounting_posting_rules WHERE tenant_id = :tenant AND id = :id');
    $templateStmt = $pdo->prepare('SELECT name FROM accounting_journal_templates WHERE tenant_id = :tenant AND id = :id');
    $ruleStmt->execute(['tenant' => $tenantId, 'id' => $default['rule_id']]);
    $assert($ruleStmt->fetchColumn() === $newName, 'untouched legacy rule renamed to issued');
    $templateStmt->execute(['tenant' => $tenantId, 'id' => $default['template_id']]);
    $assert($templateStmt->fetchColumn() === $newName, 'untouched legacy template renamed to issued');
    $ruleStmt->execute(['tenant' => $tenantId, 'id' => $customRuleId]);
    $assert($ruleStmt->fetchColumn() === $oldName, 'customized rule name preserved');
    $templateStmt->execute(['tenant' => $tenantId, 'id' => $customTemplateId]);
    $assert($templateStmt->fetchColumn() === $oldName, 'customized template name preserved');

    $count = $pdo->prepare('SELECT COUNT(*) FROM accounting_posting_rules
        WHERE tenant_id = :tenant AND event_type = "ar.invoice.issued"');
    $count->execute(['tenant' => $tenantId]);
    $before = (int) $count->fetchColumn();
    postingRulesSeedDefaults($tenantId);
    $count->execute(['tenant' => $tenantId]);
    $assert((int) $count->fetchColumn() === $before, 'reseeding does not duplicate corrected default');

    $pdo->prepare('UPDATE accounting_posting_rules SET name = "CI renamed default"
        WHERE tenant_id = :tenant AND id = :id')
        ->execute(['tenant' => $tenantId, 'id' => $default['rule_id']]);
    postingRulesSeedDefaults($tenantId);
    $count->execute(['tenant' => $tenantId]);
    $assert((int) $count->fetchColumn() === $before,
        'legacy custom-rule alias prevents a second invoice posting rule');
    $find->execute(['tenant' => $tenantId, 'rule_name' => $newName, 'template_name' => $newName]);
    $assert(!$find->fetch(PDO::FETCH_ASSOC), 'legacy alias did not create a new invoice rule');

    $pdo->exec($migration);
    $ruleStmt->execute(['tenant' => $tenantId, 'id' => $customRuleId]);
    $assert($ruleStmt->fetchColumn() === $oldName, 'repeat migration leaves customized rule intact');

    // An older tenant's default may still use the deprecated event alias.
    $pdo->prepare('UPDATE accounting_posting_rules SET name = :name, event_type = "billing.invoice.sent"
        WHERE tenant_id = :tenant AND id = :id')
        ->execute(['name' => $oldName, 'tenant' => $tenantId, 'id' => $default['rule_id']]);
    $pdo->prepare('UPDATE accounting_journal_templates SET name = :name
        WHERE tenant_id = :tenant AND id = :id')
        ->execute(['name' => $oldName, 'tenant' => $tenantId, 'id' => $default['template_id']]);
    $pdo->prepare('UPDATE accounting_posting_rules SET name = "CI custom renamed"
        WHERE tenant_id = :tenant AND id = :id')
        ->execute(['tenant' => $tenantId, 'id' => $customRuleId]);

    $pdo->prepare('INSERT INTO accounting_posting_rules
        (tenant_id, name, event_type, journal_template_id, priority, status, description)
        VALUES (:tenant, :name, "billing.invoice.sent", :template, 50, "active", "Tenant customized rule")')
        ->execute(['tenant' => $tenantId, 'name' => $oldName, 'template' => $customTemplateId]);
    $customAliasId = (int) $pdo->lastInsertId();
    $legacyMigration = file_get_contents(__DIR__ . '/../modules/accounting/migrations/041_legacy_invoice_issued_rule_label.sql');
    if ($legacyMigration === false) throw new RuntimeException('Legacy migration missing.');
    $pdo->exec($legacyMigration);

    $ruleStmt->execute(['tenant' => $tenantId, 'id' => $default['rule_id']]);
    $assert($ruleStmt->fetchColumn() === $newName, 'untouched legacy-alias rule renamed to issued');
    $templateStmt->execute(['tenant' => $tenantId, 'id' => $default['template_id']]);
    $assert($templateStmt->fetchColumn() === $newName, 'untouched legacy-alias template renamed to issued');
    $ruleStmt->execute(['tenant' => $tenantId, 'id' => $customAliasId]);
    $assert($ruleStmt->fetchColumn() === $oldName, 'customized legacy-alias rule preserved');
    $eventStmt = $pdo->prepare('SELECT event_type FROM accounting_posting_rules WHERE tenant_id = :tenant AND id = :id');
    $eventStmt->execute(['tenant' => $tenantId, 'id' => $default['rule_id']]);
    $assert($eventStmt->fetchColumn() === 'billing.invoice.sent', 'legacy routing is unchanged');

    $countAll = $pdo->prepare('SELECT COUNT(*) FROM accounting_posting_rules
        WHERE tenant_id = :tenant AND event_type IN ("ar.invoice.issued", "billing.invoice.sent")');
    $countAll->execute(['tenant' => $tenantId]);
    $before = (int) $countAll->fetchColumn();
    postingRulesSeedDefaults($tenantId);
    $countAll->execute(['tenant' => $tenantId]);
    $assert((int) $countAll->fetchColumn() === $before, 'legacy-alias default prevents duplicate canonical rule');
    $pdo->exec($legacyMigration);
    $ruleStmt->execute(['tenant' => $tenantId, 'id' => $customAliasId]);
    $assert($ruleStmt->fetchColumn() === $oldName, 'repeat legacy migration leaves customization intact');
} finally {
    $pdo->rollBack();
}
