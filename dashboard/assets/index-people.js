
console.log("App loaded");
const root = document.getElementById("root");
root.innerHTML = `
  <header style='background:#003366;color:white;padding:1rem;font-size:1.2rem;display:flex;justify-content:space-between'>
    <span>CoreFlux</span>
    <select id='module-switcher'>
      <option selected>People</option>
      <option disabled>Timesheets</option>
      <option disabled>Reporting</option>
    </select>
  </header>
  <div style='display:flex;height:calc(100vh - 64px)'>
    <aside style='width:220px;background:#f4f7fa;padding:1rem;border-right:1px solid #ccc'>
      <ul style='list-style:none;padding:0'>
        <li><a href='#directory'>Employee Directory</a></li>
        <li><a href='#timesheets'>Timesheet Tracking</a></li>
        <li><a href='#access'>Access Control</a></li>
        <li><a href='#hiring'>Hiring Pipeline</a></li>
      </ul>
    </aside>
    <main style='flex:1;padding:2rem'>
      <h2 id='section-title'>People Module</h2>
      <p>This is a placeholder for the full People module. Sections will load here.</p>
    </main>
  </div>
`;
