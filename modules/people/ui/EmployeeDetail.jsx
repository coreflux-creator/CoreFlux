import React, { useEffect, useState } from 'react';
import { useParams, NavLink, Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import AISuggestion from '../../../dashboard/src/components/AISuggestion';

/* ─────────── Profile tab ─────────── */
function ProfileTab({ employee, reload }) {
  const [aiSummary, setAiSummary] = useState(null);
  const loadSummary = async () => {
    const res = await api.post('/modules/people/api/ai_summary.php', { employee_id: employee.id });
    setAiSummary(res.ai);
  };
  return (
    <div className="emp-tab">
      <button className="btn btn--ghost" onClick={loadSummary} data-testid="people-ai-summary-btn">
        AI summary
      </button>
      {aiSummary && (
        <AISuggestion
          envelope={aiSummary}
          featureKey="people.employee_summary"
          subjectType="employee"
          subjectId={employee.id}
          editable={false}
        />
      )}
      <dl className="detail-grid">
        <dt>Legal name</dt>
        <dd>{[employee.legal_first_name, employee.legal_middle_name, employee.legal_last_name].filter(Boolean).join(' ')}</dd>
        <dt>Preferred name</dt>
        <dd>{employee.preferred_name || '—'}</dd>
        <dt>Employee #</dt><dd>{employee.employee_number}</dd>
        <dt>Status</dt><dd><span className={`badge badge--${employee.status}`}>{employee.status}</span></dd>
        <dt>SSN</dt><dd data-testid="people-ssn-masked">{employee.ssn_masked || '—'}</dd>
        <dt>Date of birth</dt><dd>{employee.date_of_birth || '—'}</dd>
        <dt>Personal email</dt><dd>{employee.personal_email || '—'}</dd>
        <dt>Citizenship</dt><dd>{employee.citizenship_status || '—'}</dd>
        <dt>Work authorization</dt><dd>{employee.work_auth_status || '—'}</dd>
      </dl>
    </div>
  );
}

/* ─────────── Employment tab ─────────── */
function EmploymentTab({ employee }) {
  return (
    <dl className="detail-grid">
      <dt>Job title</dt><dd>{employee.job_title || '—'}</dd>
      <dt>Department</dt><dd>{employee.department || '—'}</dd>
      <dt>Location</dt><dd>{employee.location || '—'}</dd>
      <dt>Employment type</dt><dd>{employee.employment_type?.replace('_', ' ')}</dd>
      <dt>FLSA</dt><dd>{employee.flsa_class?.replace('_', '-')}</dd>
      <dt>Hire date</dt><dd>{employee.hire_date || '—'}</dd>
      <dt>Start date</dt><dd>{employee.start_date || '—'}</dd>
      <dt>Work email</dt><dd>{employee.work_email || '—'}</dd>
      <dt>Manager</dt><dd>{employee.manager_id ? `Employee #${employee.manager_id}` : '—'}</dd>
      {employee.status === 'terminated' && (
        <>
          <dt>Terminated on</dt><dd>{employee.terminated_at || '—'}</dd>
          <dt>Termination reason</dt><dd>{employee.termination_reason || '—'}</dd>
        </>
      )}
    </dl>
  );
}

/* ─────────── Compensation tab ─────────── */
function CompensationTab({ employeeId }) {
  const { data, loading, error } = useApi(`/modules/people/api/compensation.php?employee_id=${employeeId}`);
  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error">Error: {error.message}</p>;
  const rows = data?.compensation ?? [];
  if (rows.length === 0) return <p className="empty">No compensation records yet.</p>;
  return (
    <table className="data-table" data-testid="people-compensation-table">
      <thead>
        <tr><th>From</th><th>To</th><th>Type</th><th>Rate</th><th>Frequency</th><th>Currency</th><th>Reason</th></tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.id}>
            <td>{r.effective_from}</td>
            <td>{r.effective_to || <em>current</em>}</td>
            <td>{r.pay_type}</td>
            <td>{(r.pay_rate_cents / 100).toLocaleString(undefined, { style: 'currency', currency: r.currency || 'USD' })}</td>
            <td>{r.pay_frequency}</td>
            <td>{r.currency}</td>
            <td>{r.reason || '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/* ─────────── Tax tab ─────────── */
function TaxTab({ employeeId }) {
  const fed = useApi(`/modules/people/api/tax_federal.php?employee_id=${employeeId}&active=1`);
  const st  = useApi(`/modules/people/api/tax_state.php?employee_id=${employeeId}&active=1`);
  const i9  = useApi(`/modules/people/api/i9.php?employee_id=${employeeId}`);
  return (
    <div className="emp-tab">
      <h3>Federal W-4 (active)</h3>
      {fed.data?.tax_federal ? (
        <dl className="detail-grid">
          <dt>Filing status</dt><dd>{fed.data.tax_federal.filing_status}</dd>
          <dt>Multiple jobs</dt><dd>{fed.data.tax_federal.multiple_jobs ? 'Yes' : 'No'}</dd>
          <dt>Extra withholding</dt><dd>${(fed.data.tax_federal.extra_withholding_cents / 100).toFixed(2)}</dd>
          <dt>Effective</dt><dd>{fed.data.tax_federal.effective_date}</dd>
        </dl>
      ) : <p className="empty">No W-4 on file.</p>}

      <h3>State withholding (active)</h3>
      {st.data?.tax_state?.length > 0 ? (
        <ul>{st.data.tax_state.map((t) => (
          <li key={t.id}>{t.state_code} — filing: {t.filing_status || '—'}, effective {t.effective_date}</li>
        ))}</ul>
      ) : <p className="empty">No state tax on file.</p>}

      <h3>I-9</h3>
      {i9.data?.i9 ? (
        <p>Status: <strong>{i9.data.i9.status}</strong> {i9.data.i9.verified_at ? `(verified ${i9.data.i9.verified_at})` : ''}</p>
      ) : <p className="empty">Not verified.</p>}
    </div>
  );
}

/* ─────────── Banking tab ─────────── */
function BankingTab({ employeeId }) {
  const { data, loading, error } = useApi(`/modules/people/api/bank_accounts.php?employee_id=${employeeId}`);
  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error">Error: {error.message}</p>;
  const accounts = data?.bank_accounts ?? [];
  if (accounts.length === 0) return <p className="empty">No direct deposit accounts.</p>;
  return (
    <table className="data-table" data-testid="people-bank-table">
      <thead>
        <tr><th>#</th><th>Type</th><th>Bank</th><th>Routing</th><th>Account</th><th>Allocation</th><th>Status</th></tr>
      </thead>
      <tbody>
        {accounts.map((a) => (
          <tr key={a.id}>
            <td>{a.priority}</td>
            <td>{a.account_type}</td>
            <td>{a.bank_name || '—'}</td>
            <td data-testid={`people-bank-routing-${a.id}`}>{a.routing_masked}</td>
            <td data-testid={`people-bank-account-${a.id}`}>{a.account_masked}</td>
            <td>
              {a.allocation_type === 'percent'       && `${(a.allocation_value / 100).toFixed(2)}%`}
              {a.allocation_type === 'fixed_amount'  && `$${(a.allocation_value / 100).toFixed(2)}`}
              {a.allocation_type === 'remainder'     && 'remainder'}
            </td>
            <td><span className={`badge badge--${a.status}`}>{a.status}</span></td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/* ─────────── Payroll Readiness (AI narrated) ─────────── */
function ReadinessBanner({ employeeId }) {
  const [state, setState] = useState(null);
  const check = async () => {
    const res = await api.post('/modules/people/api/ai_missing_fields.php', { employee_id: employeeId });
    setState(res);
  };
  useEffect(() => { check(); /* eslint-disable-line */ }, [employeeId]);
  if (!state) return null;
  if (state.ready) {
    return (
      <div className="readiness readiness--ready" data-testid="people-readiness-ready">
        All required fields are complete — this employee can be paid.
      </div>
    );
  }
  return (
    <div className="readiness readiness--gaps" data-testid="people-readiness-gaps">
      <h4>Missing before payroll:</h4>
      <ul>{state.gaps.map((g) => <li key={g}><code>{g}</code></li>)}</ul>
      {state.ai && (
        <AISuggestion
          envelope={state.ai}
          featureKey="people.missing_fields"
          subjectType="employee"
          subjectId={employeeId}
          editable={false}
        />
      )}
    </div>
  );
}

/* ─────────── Shell ─────────── */
export default function EmployeeDetail() {
  const { employeeId } = useParams();
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(`/modules/people/api/employees.php?id=${employeeId}`);
  const employee = data?.employee;

  if (loading) return <p>Loading…</p>;
  if (error || !employee) return <p className="error">Error loading employee: {error?.message || 'not found'}</p>;

  return (
    <section className="employee-detail" data-testid={`people-detail-${employee.id}`}>
      <header className="employee-detail__header">
        <button className="btn btn--ghost" onClick={() => navigate('../directory')}>← Directory</button>
        <div>
          <h2>{employee.preferred_name || employee.legal_first_name} {employee.legal_last_name}</h2>
          <p className="muted">{employee.job_title || '—'} · {employee.department || '—'} · #{employee.employee_number}</p>
        </div>
      </header>

      <ReadinessBanner employeeId={employee.id} />

      <nav className="tabs">
        <NavLink to="profile"       data-testid="tab-profile">Profile</NavLink>
        <NavLink to="employment"    data-testid="tab-employment">Employment</NavLink>
        <NavLink to="compensation"  data-testid="tab-compensation">Compensation</NavLink>
        <NavLink to="tax"           data-testid="tab-tax">Tax</NavLink>
        <NavLink to="banking"       data-testid="tab-banking">Banking</NavLink>
      </nav>

      <Routes>
        <Route index              element={<Navigate to="profile" replace />} />
        <Route path="profile"     element={<ProfileTab      employee={employee} reload={reload} />} />
        <Route path="employment"  element={<EmploymentTab   employee={employee} />} />
        <Route path="compensation"element={<CompensationTab employeeId={employee.id} />} />
        <Route path="tax"         element={<TaxTab          employeeId={employee.id} />} />
        <Route path="banking"     element={<BankingTab      employeeId={employee.id} />} />
      </Routes>
    </section>
  );
}
