import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createServer as createHttpServer } from 'node:http';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer as createViteServer } from 'vite';

const dashboardRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

test('development UI forwards backend requests without intercepting SPA routes', async () => {
  const backend = createHttpServer((req, res) => {
    if (req.url.startsWith('/login.html')) {
      res.setHeader('Content-Type', 'text/html');
      res.end('<form action="/login.php">CoreFlux sign in</form>');
      return;
    }
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Set-Cookie', 'PHPSESSID=test; Domain=staging.example.test; Path=/; HttpOnly');
    res.end(JSON.stringify({ method: req.method, path: req.url, host: req.headers.host }));
  });
  backend.listen(0, '127.0.0.1');
  await once(backend, 'listening');

  const previousOrigin = process.env.COREFLUX_BACKEND_ORIGIN;
  process.env.COREFLUX_BACKEND_ORIGIN = `http://127.0.0.1:${backend.address().port}`;
  let frontend;
  try {
    frontend = await createViteServer({
      root: dashboardRoot,
      configFile: resolve(dashboardRoot, 'vite.config.js'),
      server: { host: '127.0.0.1', port: 0 },
    });
    await frontend.listen();
    const frontendOrigin = `http://127.0.0.1:${frontend.httpServer.address().port}`;

    for (const path of [
      '/session.php',
      '/login.php',
      '/api/v1/billing/invoices.php',
      '/modules/payroll/api/runs.php',
      '/core/api/payment_rails.php',
    ]) {
      const response = await fetch(frontendOrigin + path);
      assert.equal(response.status, 200, path);
      assert.equal(response.headers.get('content-type'), 'application/json', path);
      assert.doesNotMatch(response.headers.get('set-cookie'), /Domain=/i, path);
      assert.deepEqual(await response.json(), {
        method: 'GET',
        path,
        host: `127.0.0.1:${backend.address().port}`,
      });
    }

    const login = await fetch(frontendOrigin + '/login.html?next=%2Fmodules%2Faccounting');
    assert.equal(login.status, 200);
    assert.match(login.headers.get('content-type'), /text\/html/);
    assert.match(await login.text(), /CoreFlux sign in/);

    const posted = await fetch(frontendOrigin + '/modules/billing/api/invoices.php', {
      method: 'POST',
      body: '{}',
    });
    assert.equal((await posted.json()).method, 'POST');

    const page = await fetch(frontendOrigin + '/modules/payroll/runs');
    assert.equal(page.status, 200);
    assert.match(await page.text(), /id="root"/);
  } finally {
    if (frontend) await frontend.close();
    backend.close();
    if (previousOrigin === undefined) delete process.env.COREFLUX_BACKEND_ORIGIN;
    else process.env.COREFLUX_BACKEND_ORIGIN = previousOrigin;
  }
});

test('development proxy refuses a live CoreFlux tenant by default', async () => {
  const previousOrigin = process.env.COREFLUX_BACKEND_ORIGIN;
  const previousOverride = process.env.COREFLUX_ALLOW_PRODUCTION_PROXY;
  process.env.COREFLUX_BACKEND_ORIGIN = 'https://thunderhawk.corefluxapp.com';
  delete process.env.COREFLUX_ALLOW_PRODUCTION_PROXY;
  try {
    await assert.rejects(
      createViteServer({
        root: dashboardRoot,
        configFile: resolve(dashboardRoot, 'vite.config.js'),
      }),
      /Refusing to proxy development requests to production CoreFlux/,
    );
  } finally {
    if (previousOrigin === undefined) delete process.env.COREFLUX_BACKEND_ORIGIN;
    else process.env.COREFLUX_BACKEND_ORIGIN = previousOrigin;
    if (previousOverride === undefined) delete process.env.COREFLUX_ALLOW_PRODUCTION_PROXY;
    else process.env.COREFLUX_ALLOW_PRODUCTION_PROXY = previousOverride;
  }
});
