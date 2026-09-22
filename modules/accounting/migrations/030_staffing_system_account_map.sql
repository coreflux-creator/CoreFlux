-- Correct legacy control-account labels and add the compact staffing account
-- map used by assignment-dimensional postings. Existing tenant-created rows
-- win because the chart is keyed by (tenant_id, code).

UPDATE accounting_accounts
   SET name = 'Sales Tax Payable'
 WHERE code = '2100'
   AND name = 'Payroll Liability'
   AND is_system_account = 1;

UPDATE accounting_accounts
   SET name = 'Payroll Payable'
 WHERE code = '2200'
   AND name = 'Sales Tax Payable'
   AND is_system_account = 1;

INSERT IGNORE INTO accounting_accounts
    (tenant_id, code, name, account_type, subtype, normal_side,
     is_postable, is_system_account, statement_section, sort_order, active)
SELECT t.id, defaults.code, defaults.name, defaults.account_type,
       defaults.subtype, defaults.normal_side, 1, 1,
       defaults.statement_section, defaults.sort_order, 1
  FROM tenants t
  JOIN (
        SELECT '1310' code, 'Input Tax Receivable' name, 'asset' account_type,
               'current_asset' subtype, 'debit' normal_side,
               'current_assets' statement_section, 310 sort_order
        UNION ALL SELECT '2210','Payroll Tax Payable','liability','current_liability','credit','current_liabilities',310
        UNION ALL SELECT '2220','Payroll Deduction Payable','liability','current_liability','credit','current_liabilities',320
        UNION ALL SELECT '4010','Overtime Revenue','revenue','operating_revenue','credit','revenue',110
        UNION ALL SELECT '4020','Direct Hire Revenue','revenue','operating_revenue','credit','revenue',120
        UNION ALL SELECT '4030','Conversion Fee Revenue','revenue','operating_revenue','credit','revenue',130
        UNION ALL SELECT '4040','Reimbursed Expense Revenue','revenue','operating_revenue','credit','revenue',140
        UNION ALL SELECT '5020','Employer Payroll Tax Expense','expense','cogs','debit','cogs',120
        UNION ALL SELECT '5030','FUTA Expense','expense','cogs','debit','cogs',130
        UNION ALL SELECT '5040','SUTA Expense','expense','cogs','debit','cogs',140
        UNION ALL SELECT '5050','Workers Compensation Expense','expense','cogs','debit','cogs',150
        UNION ALL SELECT '5060','Worker Benefits Expense','expense','cogs','debit','cogs',160
        UNION ALL SELECT '5070','Contractor Cost','expense','cogs','debit','cogs',170
        UNION ALL SELECT '5080','Other Direct Placement Cost','expense','cogs','debit','cogs',180
  ) defaults ON 1 = 1
 WHERE COALESCE(t.is_active, 1) = 1;
