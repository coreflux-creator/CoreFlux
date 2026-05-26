import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useGql } from '../../../dashboard/src/lib/graphqlClient';
import { Zap } from 'lucide-react';

/**
 * PlacementDetailGraphql — PILOT migration of the placement detail page
 * from REST → GraphQL. Read-only viewer; mutations stay on the existing
 * REST page (../detail) so this pilot is safe to enable per-tenant.
 *
 * Renders the canonical placement shape (header + dates + person +
 * end-client + rates) — enough for a CFO/recruiter to verify a record
 * matches what's in JobDiva without leaving the GraphQL transport.
 *
 * Mirrors ListGraphql.jsx's visual + diagnostic conventions.
 */

const PLACEMENT_QUERY = `
  query DashboardPlacement($id: ID!) {
    placement(id: $id) {
      id
      title
      status
      engagementType
      remotePolicy
      startDate
      endDate
      actualEndDate
      dueDate
      worksiteState
      worksiteCountry
      notes
      endClientName
      clientApproverName
      clientApproverEmail
      person {
        id firstName lastName preferredName emailPrimary phonePrimary
        classification employmentType status
      }
      endClient {
        id name industry website billingEmail
        billingAddress { city state country }
      }
      rates {
        billRate billRateUnit
        payRate  payRateUnit
        currency otMultiplier dtMultiplier
        effectiveFrom effectiveTo
      }
      externalMappings {
        sourceSystem kind externalId direction lastSyncedAt
      }
      createdAt updatedAt
    }
  }
`;

function Field({ label, children, testid }) {
  return (
    <div data-testid={testid} style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
      <span style={{ fontSize: 'var(--cf-text-xs)', color: 'var(--cf-text-secondary, #6b7280)', textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {label}
      </span>
      <span style={{ fontSize: 'var(--cf-text-sm)', fontWeight: 500 }}>{children ?? '—'}</span>
    </div>
  );
}

function Section({ title, testid, children }) {
  return (
    <section data-testid={testid} style={{
      padding: 'var(--cf-space-4)',
      border: '1px solid var(--cf-border, #e5e7eb)',
      borderRadius: 8,
      background: 'var(--cf-surface, #ffffff)',
      marginBottom: 'var(--cf-space-3)',
    }}>
      <h3 style={{ margin: '0 0 var(--cf-space-3)', fontSize: 14, fontWeight: 600 }}>{title}</h3>
      <div style={{
        display: 'grid',
        gap: 'var(--cf-space-3)',
        gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
      }}>
        {children}
      </div>
    </section>
  );
}

export default function PlacementDetailGraphql() {
  const { pid } = useParams();
  const { data, error, loading, elapsedMs, reload } =
    useGql(PLACEMENT_QUERY, { variables: { id: String(pid) } });

  const placement = data?.placement;

  return (
    <section data-testid="placement-detail-gql">
      <header style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
        marginBottom: 'var(--cf-space-4)',
        gap: 'var(--cf-space-3)',
        flexWrap: 'wrap',
      }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            {placement?.title || `Placement #${pid}`}
            <span
              data-testid="placement-gql-detail-badge"
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 4,
                padding: '2px 8px',
                fontSize: 'var(--cf-text-xs)', fontWeight: 600,
                background: 'rgba(124,58,237,0.12)', color: '#7c3aed',
                borderRadius: 999,
              }}
            >
              <Zap size={12} /> GraphQL
            </span>
            {placement?.status && (
              <span
                className={`badge badge--${placement.status}`}
                data-testid="placement-gql-detail-status"
                style={{ marginLeft: 'var(--cf-space-2)' }}
              >{placement.status}</span>
            )}
          </h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            {loading
              ? 'Loading…'
              : elapsedMs != null && (
                <span
                  data-testid="placement-gql-detail-perf"
                  style={{ fontSize: 'var(--cf-text-xs)', color: '#7c3aed', fontWeight: 600 }}
                >⚡ {Math.round(elapsedMs)}ms via graphql.corefluxapp.com</span>
              )}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to=".." className="btn" data-testid="placement-gql-detail-switch-rest">Switch to REST</Link>
          <button className="btn btn--ghost" onClick={reload} data-testid="placement-gql-detail-refresh">Refresh</button>
        </div>
      </header>

      {error && (
        <div data-testid="placement-gql-detail-error" style={{
          padding: 'var(--cf-space-3)',
          background: 'rgba(239,68,68,0.06)',
          border: '1px solid rgba(239,68,68,0.25)',
          borderRadius: 8,
          marginBottom: 'var(--cf-space-3)',
        }}>
          <strong style={{ color: '#b91c1c' }}>GraphQL error</strong>
          {error.code && (
            <code style={{ marginLeft: 8, fontSize: 'var(--cf-text-xs)', padding: '2px 6px', background: '#fee2e2', borderRadius: 4 }}>{error.code}</code>
          )}
          <div style={{ marginTop: 4, fontSize: 'var(--cf-text-sm)', color: '#7f1d1d' }} data-testid="placement-gql-detail-error-message">
            {error.message}
          </div>
        </div>
      )}

      {!loading && !placement && !error && (
        <div data-testid="placement-gql-detail-empty" className="empty">
          Placement not found, or you don't have access to it.
        </div>
      )}

      {placement && (
        <>
          <Section title="Engagement" testid="placement-gql-detail-engagement">
            <Field label="Engagement type" testid="placement-gql-detail-engagement-type">{placement.engagementType}</Field>
            <Field label="Remote policy"   testid="placement-gql-detail-remote-policy">{placement.remotePolicy}</Field>
            <Field label="Start date"      testid="placement-gql-detail-start">{placement.startDate}</Field>
            <Field label="End date"        testid="placement-gql-detail-end">{placement.endDate}</Field>
            <Field label="Actual end"      testid="placement-gql-detail-actual-end">{placement.actualEndDate}</Field>
            <Field label="Due date"        testid="placement-gql-detail-due">{placement.dueDate}</Field>
            <Field label="Worksite state"  testid="placement-gql-detail-worksite-state">{placement.worksiteState}</Field>
            <Field label="Worksite country"testid="placement-gql-detail-worksite-country">{placement.worksiteCountry}</Field>
          </Section>

          {placement.person && (
            <Section title="Person" testid="placement-gql-detail-person">
              <Field label="Name"           testid="placement-gql-detail-person-name">
                {[placement.person.firstName, placement.person.lastName].filter(Boolean).join(' ') || placement.person.preferredName}
              </Field>
              <Field label="Email"          testid="placement-gql-detail-person-email">{placement.person.emailPrimary}</Field>
              <Field label="Phone"          testid="placement-gql-detail-person-phone">{placement.person.phonePrimary}</Field>
              <Field label="Classification" testid="placement-gql-detail-person-classification">{placement.person.classification}</Field>
              <Field label="Employment"     testid="placement-gql-detail-person-employment">{placement.person.employmentType}</Field>
              <Field label="Status"         testid="placement-gql-detail-person-status">{placement.person.status}</Field>
            </Section>
          )}

          {(placement.endClient || placement.endClientName) && (
            <Section title="End client" testid="placement-gql-detail-end-client">
              <Field label="Name"           testid="placement-gql-detail-end-client-name">
                {placement.endClient?.name || placement.endClientName}
              </Field>
              <Field label="Industry"       testid="placement-gql-detail-end-client-industry">{placement.endClient?.industry}</Field>
              <Field label="Website"        testid="placement-gql-detail-end-client-website">
                {placement.endClient?.website
                  ? <a href={placement.endClient.website} target="_blank" rel="noreferrer">{placement.endClient.website.replace(/^https?:\/\//, '')}</a>
                  : null}
              </Field>
              <Field label="Billing email"  testid="placement-gql-detail-end-client-billing-email">{placement.endClient?.billingEmail}</Field>
              <Field label="Location"       testid="placement-gql-detail-end-client-location">
                {[placement.endClient?.billingAddress?.city, placement.endClient?.billingAddress?.state, placement.endClient?.billingAddress?.country]
                  .filter(Boolean).join(', ')}
              </Field>
              <Field label="Client approver" testid="placement-gql-detail-end-client-approver">
                {placement.clientApproverName}
                {placement.clientApproverEmail && (
                  <span style={{ color: 'var(--cf-text-secondary)', marginLeft: 4 }}>
                    &lt;{placement.clientApproverEmail}&gt;
                  </span>
                )}
              </Field>
            </Section>
          )}

          {placement.rates && (
            <Section title="Rates (current snapshot)" testid="placement-gql-detail-rates">
              <Field label="Bill rate" testid="placement-gql-detail-rates-bill">
                {placement.rates.billRate} {placement.rates.currency}/{placement.rates.billRateUnit}
              </Field>
              <Field label="Pay rate" testid="placement-gql-detail-rates-pay">
                {placement.rates.payRate} {placement.rates.currency}/{placement.rates.payRateUnit}
              </Field>
              <Field label="OT multiplier" testid="placement-gql-detail-rates-ot">{placement.rates.otMultiplier}</Field>
              <Field label="DT multiplier" testid="placement-gql-detail-rates-dt">{placement.rates.dtMultiplier}</Field>
              <Field label="Effective from" testid="placement-gql-detail-rates-from">{placement.rates.effectiveFrom}</Field>
              <Field label="Effective to"   testid="placement-gql-detail-rates-to">{placement.rates.effectiveTo}</Field>
            </Section>
          )}

          {placement.externalMappings?.length > 0 && (
            <Section title="External mappings" testid="placement-gql-detail-external">
              {placement.externalMappings.map((m, i) => (
                <Field
                  key={`${m.sourceSystem}-${m.kind}-${i}`}
                  label={`${m.sourceSystem} · ${m.kind}`}
                  testid={`placement-gql-detail-external-${m.sourceSystem}-${m.kind}`}
                >
                  {m.externalId}
                  {m.lastSyncedAt && (
                    <span style={{ display: 'block', fontSize: 'var(--cf-text-xs)', color: 'var(--cf-text-secondary)' }}>
                      synced {new Date(m.lastSyncedAt).toLocaleString()}
                    </span>
                  )}
                </Field>
              ))}
            </Section>
          )}

          {placement.notes && (
            <Section title="Notes" testid="placement-gql-detail-notes">
              <p style={{ gridColumn: '1 / -1', whiteSpace: 'pre-wrap', margin: 0, fontSize: 'var(--cf-text-sm)' }}>
                {placement.notes}
              </p>
            </Section>
          )}
        </>
      )}
    </section>
  );
}
