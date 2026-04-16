import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: '/app/',  // Build output will be served from /app/ path
  plugins: [react()],
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    emptyOutDir: true,
  },
  server: {
    proxy: {
      // Proxy PHP requests to the PHP server during development
      '/session.php': 'http://localhost:8080',
      '/login.php': 'http://localhost:8080',
      '/logout.php': 'http://localhost:8080',
      '/dashboard.php': 'http://localhost:8080',
      '/update_active_module.php': 'http://localhost:8080',
      '/assets': 'http://localhost:8080',
    }
  }
});
