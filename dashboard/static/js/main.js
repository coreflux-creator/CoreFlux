import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, Routes, Route } from "react-router-dom";

const Login = () => (
  <div className="p-4">
    <h2>Login</h2>
    <form onSubmit={(e) => { e.preventDefault(); fetch('/login.php', { method: 'POST', body: new URLSearchParams({ email: 'test@example.com', password: 'pass' }), credentials: 'include' }).then(() => window.location.href='/dashboard'); }}>
      <input type="text" placeholder="Email" required /><br />
      <input type="password" placeholder="Password" required /><br />
      <button type="submit">Login</button>
    </form>
  </div>
);

const PeopleModule = () => {
  const [actions, setActions] = React.useState([]);
  React.useEffect(() => {
    fetch('/config/modules/People_module_actions.json')
      .then(res => res.json())
      .then(data => setActions(data));
  }, []);
  return (
    <div className="p-4">
      <h2>People Module</h2>
      <div style={{ display: 'grid', gap: '1rem' }}>
        {actions.map((item, i) => (
          <div key={i} style={{ border: '1px solid #ccc', borderRadius: '12px', padding: '1rem', cursor: 'pointer' }} onClick={() => window.location.href = item.link}>
            <img src={item.icon} alt={item.title} width="40" />
            <h3>{item.title}</h3>
            <p>{item.description}</p>
          </div>
        ))}
      </div>
    </div>
  );
};

const App = () => (
  <BrowserRouter basename="/dashboard">
    <Routes>
      <Route path="/" element={<Login />} />
      <Route path="/modules/people" element={<PeopleModule />} />
    </Routes>
  </BrowserRouter>
);

createRoot(document.getElementById("root")).render(<App />);
