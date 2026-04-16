import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: '/',  // Serve from root since spa.php handles routing
  plugins: [react()],
  build: {
    outDir: 'dist',
    assetsDir: 'spa-assets',
    emptyOutDir: true,
  },
  server: {
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
