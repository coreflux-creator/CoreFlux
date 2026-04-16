import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: '/',
  plugins: [react()],
  server: {
    proxy: {
      // Proxy PHP requests to the static server
      '/session.php': 'http://localhost:8080',
      '/login.php': 'http://localhost:8080',
      '/logout.php': 'http://localhost:8080',
      '/dashboard.php': 'http://localhost:8080',
      '/update_active_module.php': 'http://localhost:8080',
      '/assets': 'http://localhost:8080',
    }
  }
});
