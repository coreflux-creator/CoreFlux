import React, { useState, useEffect } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { ModuleHero, StatsGrid, StatCard, Section, ActionCardsGrid, ActionCard, Card, EmptyState } from '../components/UIComponents';

// API helpers
const fetchData = async (endpoint) => {
  try {
    const res = await fetch(endpoint, { credentials: 'include' });
    if (!res.ok) throw new Error('Failed to fetch');
    return await res.json();
  } catch (e) {
    console.error('API error:', e);
    return null;
  }
};

// People Overview Dashboard
const PeopleOverview = () => {
  const [stats, setStats] = useState({ employees: 0, pending: 0, hours: 0 });
  
  useEffect(() => {
    // Fetch real stats from API (placeholder - connect to actual endpoints)
    // For now using demo data
    setStats({ employees: 24, pending: 5, hours: 186 });
  }, []);

  return (
    <>
      <ModuleHero
        title="People"
        description="Manage employees, timesheets, and HR operations. Track time, approve submissions, and generate workforce reports."
        image="/assets/icons/hero-people.png"
      />

      <StatsGrid>
        <StatCard value={stats.employees} label="Employees" sublabel="+2 this month" />
        <StatCard value={stats.pending} label="Pending Timesheets" sublabel="Awaiting approval" />
        <StatCard value={stats.hours} label="Hours This Week" sublabel="Team total" />
      </StatsGrid>

      <Section title="Quick Actions">
        <ActionCardsGrid>
          <ActionCard
            icon="/assets/icons/icon-timesheet.png"
            title="Enter Time"
            description="Log your work hours"
            href="/modules/people/enter-time"
          />
          <ActionCard
            icon="/assets/icons/icon-approvals.png"
            title="Timesheets"
            description="Review and approve"
            href="/modules/people/timesheets"
          />
          <ActionCard
            icon="/assets/icons/icon-directory.png"
            title="Employee Directory"
            description="View all employees"
            href="/modules/people/employee-directory"
          />
          <ActionCard
            icon="/assets/icons/icon-reports.png"
            title="Reports"
            description="Workforce analytics"
            href="/modules/people/reports"
          />
        </ActionCardsGrid>
      </Section>
    </>
  );
};

// Enter Time Page
const EnterTime = () => {
  return (
    <>
      <ModuleHero
        title="Enter Time"
        description="Log your work hours for the current pay period."
        image="/assets/icons/icon-time-tracking.png"
      />
      
      <Section title="Current Timesheet">
        <Card>
          <EmptyState 
            title="Time Entry"
            description="Select a date and enter your hours below."
          />
          {/* TODO: Add actual time entry form */}
        </Card>
      </Section>
    </>
  );
};

// Timesheets Page
const Timesheets = () => {
  const [timesheets, setTimesheets] = useState([]);
  
  return (
    <>
      <ModuleHero
        title="Timesheets"
        description="Review, approve, or reject submitted timesheets from your team."
        image="/assets/icons/icon-approvals.png"
      />
      
      <StatsGrid>
        <StatCard value="5" label="Pending" sublabel="Needs review" />
        <StatCard value="18" label="Approved" sublabel="This period" />
        <StatCard value="1" label="Rejected" sublabel="Requires resubmission" />
      </StatsGrid>
      
      <Section title="Pending Approvals">
        <Card>
          <EmptyState 
            title="No Pending Timesheets"
            description="All timesheets have been reviewed."
          />
          {/* TODO: Add actual timesheet list */}
        </Card>
      </Section>
    </>
  );
};

// Employee Directory Page
const EmployeeDirectory = () => {
  const [employees, setEmployees] = useState([]);
  
  return (
    <>
      <ModuleHero
        title="Employee Directory"
        description="View and manage your organization's employees."
        image="/assets/icons/icon-directory.png"
      />
      
      <StatsGrid>
        <StatCard value="24" label="Total Employees" sublabel="Active" />
        <StatCard value="3" label="Departments" sublabel="Engineering, Sales, HR" />
        <StatCard value="2" label="New Hires" sublabel="This month" />
      </StatsGrid>
      
      <Section title="All Employees">
        <Card>
          <EmptyState 
            title="Employee List"
            description="Employee directory will be displayed here."
          />
          {/* TODO: Add actual employee table */}
        </Card>
      </Section>
    </>
  );
};

// Reports Page
const Reports = () => {
  return (
    <>
      <ModuleHero
        title="Reports"
        description="Generate workforce analytics and insights."
        image="/assets/icons/icon-reports.png"
      />
      
      <Section title="Available Reports">
        <ActionCardsGrid>
          <ActionCard
            icon="/assets/icons/icon-time-tracking.png"
            title="Hours Summary"
            description="Weekly/monthly breakdown"
          />
          <ActionCard
            icon="/assets/icons/icon-employee.png"
            title="Headcount Report"
            description="Employee statistics"
          />
          <ActionCard
            icon="/assets/icons/icon-performance.png"
            title="Attendance"
            description="Attendance tracking"
          />
          <ActionCard
            icon="/assets/icons/icon-custom-reports.png"
            title="Custom Report"
            description="Build your own"
          />
        </ActionCardsGrid>
      </Section>
    </>
  );
};

// Hiring Pipeline Page
const HiringPipeline = () => {
  return (
    <>
      <ModuleHero
        title="Hiring Pipeline"
        description="Track candidates through your recruitment process."
        image="/assets/icons/icon-hiring.png"
      />
      
      <StatsGrid>
        <StatCard value="12" label="Open Positions" sublabel="Actively hiring" />
        <StatCard value="45" label="Candidates" sublabel="In pipeline" />
        <StatCard value="3" label="Interviews" sublabel="Scheduled this week" />
      </StatsGrid>
      
      <Section title="Pipeline Overview">
        <Card>
          <EmptyState 
            title="Hiring Pipeline"
            description="Candidate tracking will be displayed here."
          />
        </Card>
      </Section>
    </>
  );
};

// Main People Module with routing
const PeopleModule = ({ session }) => {
  return (
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
};

export default PeopleModule;
