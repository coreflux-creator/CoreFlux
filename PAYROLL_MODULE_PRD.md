# CoreFlux Payroll Module - Product Requirements Document

## Overview

A comprehensive payroll processing platform designed to be integrated into CoreFlux as a standalone module. First iteration targets feature parity with basic tiers of ADP, Gusto, and Paychex.

---

## Target Users

| Role | Description |
|------|-------------|
| **Payroll Admin** | Runs payroll, manages employee pay, handles tax filings |
| **HR Manager** | Manages employee onboarding, compensation changes |
| **Employee** | Views pay stubs, updates tax withholdings, manages direct deposit |
| **Accountant** | Reviews payroll reports, GL postings, reconciliation |
| **Company Admin** | Configures company settings, pay schedules, approvals |

---

## Core Feature Areas

### 1. Company Setup & Configuration

#### 1.1 Company Profile
- Legal business name, DBA
- EIN (Federal Tax ID)
- State tax IDs (for each state with employees)
- Business address (headquarters, work locations)
- Industry classification (NAICS code)
- Company size tier

#### 1.2 Pay Schedules
- Pay frequency options:
  - Weekly
  - Bi-weekly
  - Semi-monthly (1st & 15th, or 15th & last)
  - Monthly
- Pay period start/end dates
- Pay date configuration
- Multiple pay schedules per company (hourly vs salary)

#### 1.3 Departments & Cost Centers
- Department hierarchy
- Cost center codes
- GL account mapping per department
- Budget tracking integration

#### 1.4 Approval Workflows
- Payroll submission → review → approval chain
- Multi-level approvals (manager → payroll admin → finance)
- Approval thresholds
- Delegation rules

---

### 2. Employee Management

#### 2.1 Employee Onboarding
- Personal information
  - Full legal name
  - SSN (encrypted storage)
  - Date of birth
  - Contact info (address, phone, email)
  - Emergency contacts
- Employment details
  - Hire date
  - Job title
  - Department
  - Work location
  - Manager/supervisor
  - Employment type (full-time, part-time, contractor)
  - FLSA status (exempt/non-exempt)

#### 2.2 Compensation Setup
- Pay type (salary, hourly, commission)
- Pay rate (annual salary or hourly rate)
- Default hours per period
- Overtime eligibility
- Shift differentials
- Commission structures
- Bonus eligibility

#### 2.3 Tax Withholding (Federal)
- W-4 form data capture
  - Filing status (single, married, head of household)
  - Multiple jobs checkbox
  - Dependents credit
  - Other income
  - Deductions
  - Extra withholding
- 2020+ W-4 AND legacy W-4 support

#### 2.4 Tax Withholding (State/Local)
- State W-4 equivalents
- State-specific forms (CA DE-4, NY IT-2104, etc.)
- Local tax jurisdictions
- Reciprocity agreements
- Work location vs residence location

#### 2.5 Direct Deposit
- Multiple bank accounts
- Split deposits (% or fixed amount)
- Account validation (prenote/micro-deposits)
- Pay card option
- Paper check fallback

#### 2.6 Deductions & Benefits
- Pre-tax deductions
  - 401(k) / retirement (% or fixed)
  - HSA / FSA contributions
  - Health insurance premiums
  - Transit benefits
- Post-tax deductions
  - Roth 401(k)
  - Life insurance
  - Disability insurance
  - Garnishments (child support, tax levies, creditor)
  - Union dues
  - Loan repayments

#### 2.7 Garnishments
- Court-ordered withholdings
- Child support orders
- Tax levies (IRS, state)
- Creditor garnishments
- Priority/allocation rules
- Disposable income calculations
- Agency remittance tracking

---

### 3. Time & Attendance Integration

#### 3.1 Hours Input
- Manual entry by admin
- Employee self-service time entry
- Import from time tracking systems
- Integration with CoreFlux People module

#### 3.2 Hour Types
- Regular hours
- Overtime (1.5x, 2x)
- Double-time
- Holiday pay
- PTO / Vacation
- Sick time
- Jury duty
- Bereavement
- Custom earning codes

#### 3.3 Overtime Rules
- Federal FLSA (40 hrs/week)
- State-specific rules
  - California (daily OT, 7th day rules)
  - Other state variations
- Automatic OT calculation
- Weighted average OT for multiple rates

---

### 4. Payroll Processing

#### 4.1 Payroll Run Types
- Regular payroll
- Off-cycle / bonus runs
- Commission runs
- Final pay (termination)
- Correction runs
- Void & reissue

#### 4.2 Gross Pay Calculation
- Regular earnings (salary ÷ periods, or hours × rate)
- Overtime earnings
- Shift differentials
- Bonuses
- Commissions
- Retroactive pay
- Reimbursements (non-taxable)
- Tips

#### 4.3 Tax Calculations
**Federal Taxes:**
- Federal income tax (FIT) - per W-4 & IRS tables
- Social Security (6.2% up to wage base)
- Medicare (1.45% + 0.9% additional over $200k)

**Employer Taxes:**
- Social Security match (6.2%)
- Medicare match (1.45%)
- FUTA (6% on first $7k, reduced by SUTA credit)
- SUTA (state-specific rates)

**State Taxes:**
- State income tax (each state's method)
- State disability (CA SDI, NY DBL, etc.)
- Paid family leave
- Local/city taxes

#### 4.4 Deduction Processing
- Pre-tax deduction order
- Taxable wage calculation
- Post-tax deduction order
- Garnishment calculations (disposable income limits)
- Catch-up contributions (401k over 50)

#### 4.5 Net Pay Calculation
- Gross pay
- − Pre-tax deductions
- = Taxable wages
- − Tax withholdings
- − Post-tax deductions
- = Net pay

#### 4.6 Payroll Preview & Approval
- Pre-submission preview
- Variance alerts (significant changes from prior period)
- Error/warning flags
- Manager approval workflow
- Final submission lock

---

### 5. Payment Processing

#### 5.1 Direct Deposit (ACH)
- NACHA file generation
- Bank integration / payment processor
- Prenote validation
- 2-day vs same-day ACH
- Failed payment handling
- Reversal processing

#### 5.2 Paper Checks
- Check printing (MICR)
- Check stock management
- Void check tracking
- Uncashed check monitoring
- Escheatment tracking

#### 5.3 Pay Cards
- Paycard provider integration
- Card issuance
- Balance inquiries
- Fee disclosures

#### 5.4 Funding
- Company bank account setup
- Funding timeline
- Cash requirements forecast
- Insufficient funds handling

---

### 6. Tax Filing & Compliance

#### 6.1 Tax Deposits
- Federal tax deposits (EFTPS)
- Deposit frequency determination
  - Monthly depositor
  - Semi-weekly depositor
  - Next-day (large deposits)
- State tax deposits
- Deposit calendar & reminders

#### 6.2 Quarterly Filings
- **Form 941** - Employer's Quarterly Federal Tax Return
- **Form 940** - Annual FUTA (can pay quarterly)
- **State quarterly returns** - Each state's equivalent
- **Local filings** - City/county returns

#### 6.3 Year-End Processing
- **W-2** - Wage and Tax Statement
  - Employee copies
  - SSA submission (electronic)
  - State copies
- **W-3** - Transmittal of W-2s
- **1099-NEC** - Nonemployee Compensation
- **1099-MISC** - Miscellaneous
- Reconciliation (W-2 totals vs quarterly filings)

#### 6.4 New Hire Reporting
- State new hire reports (within 20 days)
- Multi-state employer reporting
- Electronic submission

#### 6.5 Compliance Monitoring
- Minimum wage tracking
- Overtime compliance
- ACA (Affordable Care Act) tracking
  - FTE calculations
  - Measurement periods
  - 1095-C preparation
- Workers' compensation reporting

---

### 7. Employee Self-Service Portal

#### 7.1 Pay Information
- View pay stubs (current & historical)
- Download pay stubs (PDF)
- Year-to-date earnings summary
- Projected annual income

#### 7.2 Tax Documents
- W-2 access (current & prior years)
- 1095-C access
- Download tax documents

#### 7.3 Withholding Updates
- Federal W-4 updates
- State withholding updates
- Tax calculator tool
- Withholding change history

#### 7.4 Direct Deposit Management
- Add/edit bank accounts
- Change deposit allocations
- View pending changes

#### 7.5 Personal Info Updates
- Address changes
- Contact info
- Emergency contacts
- (Triggers compliance updates like state tax changes)

---

### 8. Reporting & Analytics

#### 8.1 Standard Reports
- **Payroll Register** - Detailed breakdown per employee
- **Payroll Summary** - Totals by department, location
- **Tax Liability** - Federal, state, local taxes owed
- **Deduction Register** - All deductions by type
- **Labor Distribution** - Hours by department/cost center
- **Bank Reconciliation** - Payments vs deposits
- **Check Register** - All checks issued

#### 8.2 Tax Reports
- Quarterly tax summary
- Annual tax summary
- State-by-state breakdown
- W-2 preview report
- 941 worksheet

#### 8.3 Compliance Reports
- Minimum wage audit
- Overtime analysis
- ACA eligibility tracking
- Workers' comp payroll report

#### 8.4 Custom Reports
- Report builder
- Filter/group by any field
- Export (PDF, Excel, CSV)
- Scheduled report delivery

#### 8.5 Analytics Dashboard
- Payroll cost trends
- Headcount trends
- Labor cost % of revenue
- Overtime trends
- Benefits cost analysis

---

### 9. Integrations

#### 9.1 CoreFlux Integrations
- **People Module** - Employee data sync, time tracking
- **Accounting Module** - GL journal entries
- **Finance Module** - Budget vs actual labor costs

#### 9.2 External Integrations
- Time tracking systems (When I Work, TSheets, etc.)
- Benefits administration
- 401(k) providers (Principal, Fidelity, etc.)
- Workers' comp insurance
- Background check services
- E-Verify

#### 9.3 Banking & Payments
- ACH processor (e.g., Plaid, Dwolla)
- Bank account verification
- Positive pay files

#### 9.4 Tax Filing Services
- Federal e-file (IRS)
- State e-file connections
- Third-party tax filing services

---

## Data Model (Key Entities)

```
┌─────────────────────────────────────────────────────────────────┐
│                         COMPANY                                  │
│  id, name, ein, addresses[], pay_schedules[], settings          │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         EMPLOYEE                                 │
│  id, company_id, personal_info, employment_info,                │
│  compensation, tax_info, direct_deposits[], deductions[]        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       PAY_PERIOD                                 │
│  id, company_id, schedule_id, start_date, end_date,             │
│  pay_date, status (draft/approved/paid/closed)                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                        PAYROLL                                   │
│  id, pay_period_id, employee_id, hours[], earnings[],           │
│  taxes[], deductions[], gross_pay, net_pay, status              │
└─────────────────────────────────────────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          ▼                   ▼                   ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│    EARNINGS     │  │      TAXES      │  │   DEDUCTIONS    │
│ type, hours,    │  │ type, taxable   │  │ type, amount,   │
│ rate, amount    │  │ wages, amount   │  │ pre/post_tax    │
└─────────────────┘  └─────────────────┘  └─────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                        PAYMENT                                   │
│  id, payroll_id, method (ach/check), amount, status,            │
│  bank_account_id, check_number, processed_date                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      TAX_FILING                                  │
│  id, company_id, form_type (941/940/W2), period,                │
│  amounts{}, status (draft/filed), filed_date                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## MVP Feature Phases

### Phase 1: Core Payroll (MVP)
- Company & pay schedule setup
- Employee management (basic)
- Manual hours entry
- Gross-to-net calculation
- Federal + 1 state tax
- Direct deposit (single account)
- Basic payroll register
- Pay stub generation

### Phase 2: Tax & Compliance
- Multi-state tax support
- Tax deposit tracking
- Form 941 generation
- W-2 generation
- New hire reporting
- Garnishment processing

### Phase 3: Self-Service & Automation
- Employee self-service portal
- W-4 online updates
- Direct deposit self-management
- Automated tax calculations
- Overtime auto-calculation
- Approval workflows

### Phase 4: Advanced Features
- Multiple pay schedules
- Complex deductions (catch-up, limits)
- Benefits integration
- 401(k) integration
- ACA compliance
- Custom reporting
- API integrations

### Phase 5: Scale & Enterprise
- Multi-company support
- Advanced analytics
- Audit trails
- Role-based permissions
- White-label options
- International (Canada, UK)

---

## Technical Considerations

### Security Requirements
- **SSN Encryption** - AES-256 at rest, TLS in transit
- **PCI Compliance** - For bank account data
- **SOC 2 Type II** - For enterprise customers
- **Role-based access** - Granular permissions
- **Audit logging** - All payroll actions tracked
- **Two-factor auth** - Required for payroll submission

### Performance Requirements
- Payroll calculation: < 5 seconds per employee
- Report generation: < 30 seconds for 1000 employees
- API response time: < 500ms
- 99.9% uptime SLA

### Compliance Requirements
- IRS e-file certified
- State agency certifications
- Multi-state tax table updates (monthly)
- Regulatory change monitoring

---

## Competitive Analysis

| Feature | ADP RUN | Gusto | Paychex Flex | **CoreFlux Payroll** |
|---------|---------|-------|--------------|---------------------|
| Unlimited payroll runs | ✓ | ✓ | ✓ | ✓ |
| Multi-state | Add-on | ✓ | ✓ | ✓ |
| Direct deposit | ✓ | ✓ | ✓ | ✓ |
| Tax filing | ✓ | ✓ | ✓ | ✓ |
| Employee self-service | ✓ | ✓ | ✓ | ✓ |
| Time tracking | Add-on | Basic | Add-on | Integrated (People) |
| Benefits admin | Add-on | ✓ | Add-on | Phase 4 |
| Accounting integration | ✓ | ✓ | ✓ | Native (Accounting) |
| API access | Enterprise | ✓ | Enterprise | ✓ |
| **Starting price** | $79/mo | $40/mo | $59/mo | TBD |

---

## Success Metrics

### Business Metrics
- Time to run payroll (target: < 5 minutes for 50 employees)
- Payroll error rate (target: < 0.1%)
- Customer support tickets per payroll run
- Time to onboard new company (target: < 1 hour)

### Technical Metrics
- System uptime (target: 99.9%)
- Calculation accuracy (target: 100%)
- Tax filing success rate (target: 99.9%)
- Payment processing success rate (target: 99.95%)

---

## Open Questions

1. **Payment processing partner** - Build vs partner (Plaid, Dwolla, Check)?
2. **Tax filing** - Direct IRS/state integration vs third-party (Tax Bandits)?
3. **Pricing model** - Per employee, flat rate, or tiered?
4. **Initial state coverage** - All 50 states or phased rollout?
5. **Contractor payments** - Include 1099 in MVP?
6. **International** - US-only first or include Canada?

---

## Next Steps

1. Review and prioritize MVP features
2. Design database schema
3. Create UI/UX mockups
4. Identify integration partners (banking, tax filing)
5. Compliance review with payroll expert
6. Development sprint planning

---

*Document Version: 1.0*
*Last Updated: April 2025*
