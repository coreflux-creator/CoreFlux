import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { ModuleHero, Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { Clock, FileText, Users, PieChart, Briefcase } from 'lucide-react';

const PeopleOverview = () => (
  <>
    <ModuleHero
      title="People"
      description="Manage employees, timesheets, and HR operations. Track time, approve submissions, and generate workforce reports."
      image="/assets/icons/hero-people.png"
    />

    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="24" label="Employees" type="employees" />
        <StatCard value="5" label="Pending Timesheets" type="pending" />
        <StatCard value="186" label="Hours This Week" type="hours" />
        <StatCard value="18" label="Approved" type="approved" />
      </StatsGrid>
    </Section>

    <Section title="Quick Actions">
      <ActionCardsGrid>
        <ActionCard icon={Clock} title="Enter Time" description="Log your work hours" href="/modules/people/enter-time" />
        <ActionCard icon={FileText} title="Timesheets" description="Review and approve" href="/modules/people/timesheets" />
        <ActionCard icon={Users} title="Employee Directory" description="View all employees" href="/modules/people/employee-directory" />
      </ActionCardsGrid>
    </Section>
  </>
);

const EnterTime = () => (
  <>
    <ModuleHero title="Enter Time" description="Log your work hours for the current pay period." />
    <Section title="Current Timesheet">
      <div className="stat-card" style={{ padding: '40px', textAlign: 'center' }}>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Time entry form will be displayed here.</p>
      </div>
    </Section>
  </>
);

const Timesheets = () => (
  <>
    <ModuleHero title="Timesheets" description="Review, approve, or reject submitted timesheets from your team." />
    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="5" label="Pending" type="pending" />
        <StatCard value="18" label="Approved" type="approved" />
        <StatCard value="1" label="Rejected" type="this_month" />
        <StatCard value="186" label="Total Hours" type="hours" />
      </StatsGrid>
    </Section>
  </>
);

const EmployeeDirectory = () => (
  <>
    <ModuleHero title="Employee Directory" description="View and manage your organization's employees." />
    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="24" label="Total Employees" type="employees" />
        <StatCard value="3" label="Departments" type="completed" />
        <StatCard value="2" label="New Hires" type="this_month" />
        <StatCard value="22" label="Active" type="approved" />
      </StatsGrid>
    </Section>
  </>
);

const Reports = () => (
  <>
    <ModuleHero title="Reports" description="Generate workforce analytics and insights." />
    <Section title="Available Reports">
      <ActionCardsGrid>
        <ActionCard icon={Clock} title="Hours Summary" description="Weekly/monthly breakdown" />
        <ActionCard icon={Users} title="Headcount Report" description="Employee statistics" />
        <ActionCard icon={PieChart} title="Attendance" description="Attendance tracking" />
      </ActionCardsGrid>
    </Section>
  </>
);

const HiringPipeline = () => (
  <>
    <ModuleHero title="Hiring Pipeline" description="Track candidates through your recruitment process." />
    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="12" label="Open Positions" type="pending" />
        <StatCard value="45" label="Candidates" type="employees" />
        <StatCard value="3" label="Interviews" type="this_month" />
        <StatCard value="8" label="Offers Made" type="completed" />
      </StatsGrid>
    </Section>
  </>
);

const PeopleModule = ({ session }) => (
  <Routes>
    <Route path="/" element={<Navigate to="overview" replace />} />
    <Route path="overview" element={<PeopleOverview />} />
    <Route path="enter-time" element={<EnterTime />} />
    <Route path="enter_time" element={<Navigate to="../enter-time" replace />} />
    <Route path="timesheets" element={<Timesheets />} />
    <Route path="employee-directory" element={<EmployeeDirectory />} />
    <Route path="employee_directory" element={<Navigate to="../employee-directory" replace />} />
    <Route path="reports" element={<Reports />} />
    <Route path="hiring-pipeline" element={<HiringPipeline />} />
    <Route path="hiring_pipeline" element={<Navigate to="../hiring-pipeline" replace />} />
    <Route path="*" element={<Navigate to="overview" replace />} />
  </Routes>
);

export default PeopleModule;
