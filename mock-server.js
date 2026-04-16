/**
 * Mock API Server for React SPA Testing
 * Simulates the PHP backend endpoints
 */

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = 8080;

// Mock session data (simulating logged-in user)
const mockSession = {
  user: {
    id: 1,
    first_name: 'Kunal',
    last_name: '',
    email: 'kunal@coreflux.app',
    role: 'admin',
    global_role: 'tenant_admin',
    avatar: null
  },
  modules: [
    {
      id: 'accounting',
      name: 'Accounting',
      icon: '/assets/icons/icon-accounting.png',
      description: 'General ledger and financial reporting',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Chart of Accounts', route: 'chart_of_accounts' },
        { name: 'Journal Entries', route: 'journal_entries' },
        { name: 'General Ledger', route: 'general_ledger' },
        { name: 'Accounts Payable', route: 'accounts_payable' },
        { name: 'Accounts Receivable', route: 'accounts_receivable' },
        { name: 'Reports', route: 'reports' },
        { name: 'Settings', route: 'settings' }
      ]
    },
    {
      id: 'people',
      name: 'People',
      icon: '/assets/icons/icon-people.png',
      description: 'HR and workforce management',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Enter Time', route: 'enter_time' },
        { name: 'Timesheets', route: 'timesheets' },
        { name: 'Employee Directory', route: 'employee_directory' },
        { name: 'Reports', route: 'reports' },
        { name: 'Hiring Pipeline', route: 'hiring_pipeline' }
      ]
    },
    {
      id: 'finance',
      name: 'Finance',
      icon: '/assets/icons/icon-finance.png',
      description: 'Budgeting and forecasting',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Budgets', route: 'budgets' },
        { name: 'Forecasts', route: 'forecasts' },
        { name: 'Reports', route: 'reports' }
      ]
    }
  ],
  tenant: 'CoreFlux',
  tenant_id: 1,
  tenants: [
    { id: 1, name: 'CoreFlux', role: 'admin' },
    { id: 2, name: 'Acme Corp', role: 'employee' }
  ],
  active_module: null
};

// Set initial active module
mockSession.active_module = mockSession.modules[0];

// MIME types
const mimeTypes = {
  '.html': 'text/html',
  '.js': 'application/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon'
};

const server = http.createServer((req, res) => {
  const url = req.url.split('?')[0];
  
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Credentials', 'true');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  
  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // API Endpoints
  if (url === '/session.php') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(mockSession));
    return;
  }

  if (url === '/update_active_module.php' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      try {
        const data = JSON.parse(body);
        const mod = mockSession.modules.find(m => m.id === data.module);
        if (mod) {
          mockSession.active_module = mod;
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ success: true, module: mod.name }));
        } else {
          res.writeHead(404, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ error: 'Module not found' }));
        }
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Invalid request' }));
      }
    });
    return;
  }

  // Static files
  let filePath = path.join(__dirname, url === '/' ? '/app/index.html' : url);
  
  // Check if file exists
  if (!fs.existsSync(filePath)) {
    // Try with /app prefix for React routes
    if (url.startsWith('/modules/') || url === '/dashboard') {
      filePath = path.join(__dirname, '/app/index.html');
    }
  }

  fs.readFile(filePath, (err, data) => {
    if (err) {
      // 404 - serve index.html for client-side routing
      const indexPath = path.join(__dirname, '/app/index.html');
      fs.readFile(indexPath, (err2, data2) => {
        if (err2) {
          res.writeHead(404);
          res.end('Not Found');
        } else {
          res.writeHead(200, { 'Content-Type': 'text/html' });
          res.end(data2);
        }
      });
    } else {
      const ext = path.extname(filePath);
      const contentType = mimeTypes[ext] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': contentType });
      res.end(data);
    }
  });
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Mock API server running at http://localhost:${PORT}`);
  console.log('Simulating logged-in user: kunal@coreflux.app');
});
