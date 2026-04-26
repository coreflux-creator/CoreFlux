import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
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
      '/session.php': 'http://localhost:8080',
      '/login.php': 'http://localhost:8080',
      '/logout.php': 'http://localhost:8080',
      '/dashboard.php': 'http://localhost:8080',
      '/update_active_module.php': 'http://localhost:8080',
      '/assets': 'http://localhost:8080',
    }
  }
});
