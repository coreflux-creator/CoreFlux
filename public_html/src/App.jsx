import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import AppLayout from './layout/AppLayout';
import PeopleModule from './modules/PeopleModule';
import Login from './pages/Login';
import useSession from './hooks/useSession';

const App = () => {
  const { session, loading } = useSession();

  if (loading) return <div>Loading session...</div>;
if (!session) return <Navigate to="/login.html" />;


  return (
    <Router>
      {session ? (
        <AppLayout session={session}>
          <Routes>
            <Route path="/modules/people/*" element={<PeopleModule session={session} />} />
            {/* Add more protected routes here */}
            <Route path="*" element={<Navigate to="/modules/people" />} />
          </Routes>
        </AppLayout>
      ) : (
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="*" element={<Navigate to="/login.html" />} />
        </Routes>
      )}
    </Router>
  );
};

export default App;
