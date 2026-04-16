import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { ModuleHero, StatsGrid, StatCard, Section, ActionCardsGrid, ActionCard, Card, EmptyState } from '../components/UIComponents';

// Finance Overview
const FinanceOverview = () => {
  return (
    <>
      <ModuleHero
        title="Finance"
        description="Budget planning, forecasting, and financial analysis."
        image="/assets/icons/hero-finance.png"
      />

      <StatsGrid>
        <StatCard value="$2.4M" label="Annual Budget" sublabel="FY 2025" />
        <StatCard value="68%" label="Budget Used" sublabel="YTD" />
        <StatCard value="+12%" label="Forecast Variance" sublabel="vs. Actual" />
      </StatsGrid>

      <Section title="Quick Actions">
        <ActionCardsGrid>
          <ActionCard
            icon="/assets/icons/icon-budget.png"
            title="Budgets"
            description="View and manage budgets"
            href="/modules/finance/budgets"
          />
          <ActionCard
            icon="/assets/icons/icon-forecast.png"
            title="Forecasts"
            description="Financial projections"
            href="/modules/finance/forecasts"
          />
          <ActionCard
            icon="/assets/icons/icon-reports.png"
            title="Reports"
            description="Financial analytics"
            href="/modules/finance/reports"
          />
          <ActionCard
            icon="/assets/icons/icon-dashboards.png"
            title="Dashboards"
            description="Visual insights"
            href="/modules/finance/dashboards"
          />
        </ActionCardsGrid>
      </Section>
    </>
  );
};

// Budgets
const Budgets = () => (
  <>
    <ModuleHero
      title="Budgets"
      description="Create and manage organizational budgets."
      image="/assets/icons/icon-budget.png"
    />
    <StatsGrid>
      <StatCard value="5" label="Active Budgets" sublabel="Current period" />
      <StatCard value="$1.6M" label="Allocated" sublabel="Total budget" />
      <StatCard value="$1.1M" label="Spent" sublabel="YTD" />
    </StatsGrid>
    <Section title="Budget Overview">
      <Card>
        <EmptyState 
          title="Budget List"
          description="Your budgets will be displayed here."
        />
      </Card>
    </Section>
  </>
);

// Forecasts
const Forecasts = () => (
  <>
    <ModuleHero
      title="Forecasts"
      description="Financial projections and scenario planning."
      image="/assets/icons/icon-forecast.png"
    />
    <Section title="Active Forecasts">
      <Card>
        <EmptyState 
          title="Forecast Models"
          description="Your financial forecasts will be displayed here."
        />
      </Card>
    </Section>
  </>
);

// Reports
const FinanceReports = () => (
  <>
    <ModuleHero
      title="Financial Reports"
      description="Comprehensive financial analytics and reporting."
      image="/assets/icons/icon-reports.png"
    />
    <Section title="Available Reports">
      <ActionCardsGrid>
        <ActionCard
          icon="/assets/icons/icon-budget.png"
          title="Budget vs Actual"
          description="Variance analysis"
        />
        <ActionCard
          icon="/assets/icons/icon-forecast.png"
          title="Forecast Accuracy"
          description="Projection vs reality"
        />
        <ActionCard
          icon="/assets/icons/metrics.png"
          title="KPI Dashboard"
          description="Key metrics"
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

// Main Finance Module
const FinanceModule = ({ session }) => {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="overview" replace />} />
      <Route path="overview" element={<FinanceOverview />} />
      <Route path="budgets" element={<Budgets />} />
      <Route path="forecasts" element={<Forecasts />} />
      <Route path="reports" element={<FinanceReports />} />
      <Route path="*" element={<Navigate to="overview" replace />} />
    </Routes>
  );
};

export default FinanceModule;
