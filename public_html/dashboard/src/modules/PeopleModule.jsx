import React, { useEffect, useState } from 'react';

const PeopleModule = () => {
  const [actions, setActions] = useState([]);

  useEffect(() => {
    fetch('/config/modules/People_module_actions.json')
      .then((res) => res.json())
      .then((data) => setActions(data))
      .catch((err) => console.error('Failed to load actions:', err));
  }, []);

  return (
    <div className="people-module p-4">
      <h2>People Module</h2>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {actions.map((item, i) => (
          <div
            key={i}
            className="border rounded-2xl p-4 shadow hover:shadow-lg transition"
            onClick={() => window.location.href = item.link}
            style={{ cursor: 'pointer' }}
          >
            <img src={item.icon} alt={item.title} className="h-10 w-10 mb-2" />
            <h3 className="text-xl font-semibold">{item.title}</h3>
            <p className="text-sm text-gray-600">{item.description}</p>
          </div>
        ))}
      </div>
    </div>
  );
};

export default PeopleModule;
