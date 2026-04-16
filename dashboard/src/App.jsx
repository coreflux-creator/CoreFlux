import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import AppLayout from './layout/AppLayout';
import PeopleModule from './modules/PeopleModule';
import Login from './pages/Login';

// Mock session for demo purposes
const MOCK_SESSION = {
  user: {
    id: 1,
    first_name: 'Demo',
    last_name: 'User',
    email: 'demo@coreflux.app',
    role: 'admin'
  },
  tenant: 'Acme Corp',
  tenants: ['Acme Corp', 'Beta Industries', 'Gamma LLC'],
  modules: [
    { name: 'People', route: '/modules/people', icon: '/assets/icons/people.png' },
    { name: 'Accounting', route: '/modules/accounting', icon: '/assets/icons/accounting.png' },
    { name: 'Finance', route: '/modules/finance', icon: '/assets/icons/finance.png' }
  ],
  active_module: {
    name: 'People',
    actions: [
      { name: 'Employee Directory', route: 'employee_directory.php' },
      { name: 'Add Employee', route: 'add_employee.php' },
      { name: 'Timesheets', route: 'timesheets.php' },
      { name: 'Hiring Pipeline', route: 'hiring_pipeline.php' },
      { name: 'Reports', route: 'reports.php' }
    ]
  }
};

const App = () => {
  // Use mock session for demo - in production, fetch from /session.php
  const session = MOCK_SESSION;
  const loading = false;

  if (loading) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100vh' }}>
        Loading session...
      </div>
    );
  }

  return (
    <Router>
      {session ? (
        <AppLayout session={session}>
          <Routes>
            <Route path="/modules/people/*" element={<PeopleModule session={session} />} />
            <Route path="/modules/accounting/*" element={<div style={{ padding: '2rem' }}><h2>Accounting Module</h2><p>Coming soon...</p></div>} />
            <Route path="/modules/finance/*" element={<div style={{ padding: '2rem' }}><h2>Finance Module</h2><p>Coming soon...</p></div>} />
            <Route path="*" element={<Navigate to="/modules/people" replace />} />
          </Routes>
        </AppLayout>
      ) : (
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
      )}
    </Router>
  );
};

export default App;
