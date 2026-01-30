
console.log("🔧 CoreFlux Dashboard: Layout loaded");

document.getElementById('root').innerHTML = `
  <header style='
    background: #003366;
    color: white;
    padding: 1rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
  '>
    <div style='display: flex; align-items: center; gap: 1rem'>
      <img src='/dashboard/assets/logo.png' alt='CoreFlux Logo' style='height: 48px'/>
      <span style='font-size: 1.2rem; font-weight: bold; letter-spacing: 0.5px;'>CoreFlux</span>
    </div>
    <div style='display: flex; align-items: center; gap: 1rem'>
      <select id='module-select' style='padding: 0.4rem; border-radius: 4px; font-size: 0.95rem'>
        <option>People</option>
        <option>Finance</option>
        <option>CRM</option>
        <option>Accounting</option>
        <option>Tax</option>
        <option>Wealth Management</option>
        <option>Reporting</option>
      </select>
      <select id='tenant-select' style='padding: 0.4rem; border-radius: 4px; font-size: 0.95rem'>
        <option>HQ</option>
        <option>Branch 1</option>
      </select>
      <div style='width: 36px; height: 36px; border-radius: 50%; background: #ccc; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;'>
        U
      </div>
    </div>
  </header>

  <div style='display: flex; height: calc(100vh - 80px);'>
    <aside id='sidebar' style='
      width: 220px;
      background: #fff;
      padding: 1rem;
      border-right: 1px solid #ddd;
      display: none;
    '>
      <ul style='list-style: none; padding: 0; font-size: 0.95rem'>
        <li style='margin-bottom: 1rem'><a href='#enter' style='text-decoration: none; color: #003366'>➤ Enter Time</a></li>
        <li style='margin-bottom: 1rem'><a href='#view' style='text-decoration: none; color: #003366'>📄 View Timesheets</a></li>
        <li><a href='#reports' style='text-decoration: none; color: #003366'>📊 Generate Reports</a></li>
      </ul>
    </aside>

    <main id='main-content' style='flex: 1; padding: 2rem; background: #f4f7fa;'>
      <h2 style='color: #003366; font-size: 1.5rem;'>People Module</h2>
      <p style='color: #555; margin-bottom: 2rem;'>This is the working layout. Cards will load here.</p>
      <button onclick="document.getElementById('sidebar').style.display='block'"
              style='padding: 0.5rem 1rem; background: #003366; color: white; border: none; border-radius: 6px; cursor: pointer;'>
        Simulate Feature Click (Timesheets)
      </button>
    </main>
  </div>
`;
