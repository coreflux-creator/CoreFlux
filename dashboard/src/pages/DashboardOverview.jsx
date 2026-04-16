import React from 'react';
import { ModuleCards, Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard, HelpSection } from '../components/UIComponents';
import { Building2, Users, DollarSign } from 'lucide-react';

const DashboardOverview = ({ session, onModuleChange }) => {
  const { modules = [], user } = session;
  
  // Check if user is admin
  const isAdmin = user?.role === 'admin' || user?.global_role === 'master_admin' || user?.global_role === 'tenant_admin';

  return (
    <>
      {/* Module Access Cards */}
      <ModuleCards modules={modules} onModuleClick={onModuleChange} />

      {/* Quick Overview */}
      <Section title="Quick Overview" onRefresh={() => window.location.reload()}>
        <StatsGrid>
          <StatCard value="1" label="Active Users" type="active_users" />
          <StatCard value="0" label="This Month" type="this_month" />
          <StatCard value="$0" label="Revenue" type="revenue" />
          <StatCard value="0" label="Completed" type="completed" />
        </StatsGrid>
      </Section>

      {/* Admin Quick Actions - only show for admins */}
      {isAdmin && (
        <Section title="Admin Quick Actions">
          <ActionCardsGrid>
            <ActionCard
              icon={Building2}
              title="Manage Tenants"
              description="Create and configure tenants"
              href="/dashboard.php?page=admin&view=tenants"
            />
            <ActionCard
              icon={Users}
              title="Manage Users"
              description="Add users and assign roles"
              href="/dashboard.php?page=admin&view=users"
            />
            <ActionCard
              icon={DollarSign}
              title="Module Access"
              description="Enable modules per tenant"
              href="/dashboard.php?page=admin&view=modules"
            />
          </ActionCardsGrid>
        </Section>
      )}

      {/* Help Section */}
      <HelpSection />
    </>
  );
};

export default DashboardOverview;
