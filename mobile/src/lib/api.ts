/**
 * Typed endpoint wrappers for the mobile screens.
 * Mirrors the PHP /api/* routes shipped in Sprints 1–4.
 */
import { api } from '../api/client';

export type Placement = {
  id: number;
  title: string;
  end_client_name?: string;
  start_date: string;
  end_date?: string | null;
  engagement_type?: string;
  status?: string;
};

export type TimeCategory =
  | 'regular_billable'
  | 'regular_nonbillable'
  | 'OT_billable'
  | 'OT_nonbillable'
  | 'PTO'
  | 'sick'
  | 'holiday';

export type TimeEntry = {
  id: number;
  placement_id: number;
  work_date: string;
  category: TimeCategory;
  hours: number;
  status: 'draft' | 'pending_review' | 'approved' | 'rejected' | 'superseded';
  description?: string;
};

export const TIME_CATEGORIES: { value: TimeCategory; label: string }[] = [
  { value: 'regular_billable',    label: 'Regular (billable)' },
  { value: 'OT_billable',         label: 'Overtime (billable)' },
  { value: 'regular_nonbillable', label: 'Regular (nonbillable)' },
  { value: 'OT_nonbillable',      label: 'Overtime (nonbillable)' },
  { value: 'PTO',                 label: 'PTO' },
  { value: 'sick',                label: 'Sick' },
  { value: 'holiday',             label: 'Holiday' },
];

export const listMyPlacements   = () => api<{ placements: Placement[] }>('/api/placements?scope=mine');
export const listMyTimeEntries  = (from: string, to: string) =>
  api<{ entries: TimeEntry[] }>(`/api/time/entries?scope=mine&from=${from}&to=${to}`);

export const createTimeEntry = (e: Pick<TimeEntry, 'placement_id' | 'work_date' | 'category' | 'hours' | 'description'>) =>
  api<TimeEntry>('/api/time/entries', { method: 'POST', body: e });

export const submitTimeEntry = (id: number) =>
  api(`/api/time/entries?action=submit&id=${id}`, { method: 'POST', body: {} });

export type ReportsOverview = {
  period: { code: string; label: string; from: string; to: string };
  kpis: {
    revenue: number; gross_profit: number; gross_profit_pct: number;
    hours: number; ot_pct: number; spread_per_hour: number;
  };
};
export const reportsOverview = (period = '4w') =>
  api<ReportsOverview>(`/api/reports/overview?period=${period}`);

export type WorkflowInstance = {
  id: number;
  def_key: string;
  label: string;
  subject_type: string;
  subject_id: number;
  status: string;
  current_step: number;
  payload: Record<string, unknown>;
  sla_due_at?: string | null;
  started_at: string;
};
export const workflowInbox = () =>
  api<{ instances: WorkflowInstance[] }>(`/api/workflow.php?path=inbox`);
export const workflowGetInstance = (instanceId: number) =>
  api<{ instance: WorkflowInstance }>(`/api/workflow.php?id=${instanceId}`);
export const workflowAct = (instanceId: number, action: 'approve' | 'reject' | 'comment', comment?: string) =>
  api(`/api/workflow.php?action=act&id=${instanceId}`, {
    method: 'POST',
    body: { action, comment, via: 'app' },
  });
