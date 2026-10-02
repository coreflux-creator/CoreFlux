import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, __dirname, 'COREFLUX_');
  const backend = new URL(env.COREFLUX_BACKEND_ORIGIN || 'http://127.0.0.1:8080');
  if (!['http:', 'https:'].includes(backend.protocol)
      || backend.username || backend.password || backend.pathname !== '/'
      || backend.search || backend.hash) {
    throw new Error('COREFLUX_BACKEND_ORIGIN must be an HTTP(S) origin without a path or credentials');
  }
  const isCoreFluxHost = backend.hostname === 'corefluxapp.com'
    || backend.hostname.endsWith('.corefluxapp.com');
  const isStagingHost = ['staging.corefluxapp.com', 'stage.corefluxapp.com']
    .includes(backend.hostname);
  if (command === 'serve' && isCoreFluxHost && !isStagingHost
      && env.COREFLUX_ALLOW_PRODUCTION_PROXY !== '1') {
    throw new Error('Refusing to proxy development requests to production CoreFlux');
  }

  const backendProxy = {
    target: backend.origin,
    changeOrigin: true,
    cookieDomainRewrite: '',
  };

  return {
    base: '/',  // Serve from root since spa.php handles routing
    plugins: [react()],
    resolve: {
      // Modules live outside /app/dashboard but import shared deps (react,
      // react-router-dom, lucide-react). Force resolution to the dashboard's
      // node_modules so files under /app/modules/* can `import 'react-router-dom'`
      // without keeping a duplicate node_modules per module.
      alias: {
        'react':            path.resolve(__dirname, 'node_modules/react'),
        'react-dom':        path.resolve(__dirname, 'node_modules/react-dom'),
        'react-router-dom': path.resolve(__dirname, 'node_modules/react-router-dom'),
        'lucide-react':     path.resolve(__dirname, 'node_modules/lucide-react'),
        '@layerfi/components': path.resolve(__dirname, 'node_modules/@layerfi/components'),
      },
      dedupe: ['react', 'react-dom', 'react-router-dom'],
    },
    build: {
      outDir: 'dist',
      assetsDir: 'spa-assets',
      emptyOutDir: true,
      // Allow importing files from outside the project root (the modules tree
      // lives at /app/modules and is glued in via App.jsx).
      rollupOptions: {
        preserveEntrySignatures: 'strict',
      },
      commonjsOptions: {
        include: [/node_modules/],
      },
    },
    server: {
      fs: {
        // Allow reading files outside /app/dashboard (the /app/modules tree).
        allow: ['..'],
      },
      proxy: {
        '^/api(?:/|$)': backendProxy,
        '^/core/api(?:/|$)': backendProxy,
        '^/modules/[^/]+/api(?:/|$)': backendProxy,
        '/session.php': backendProxy,
        '/login.html': backendProxy,
        '/login.php': backendProxy,
        '/logout.php': backendProxy,
        '/switch_tenant.php': backendProxy,
        '/dashboard.php': backendProxy,
        '/update_active_module.php': backendProxy,
        '/assets': backendProxy,
      },
    },
  };
});
