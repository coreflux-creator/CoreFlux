import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// The shared LayerFi components live in /app/modules (so they can also drop
// into the real CoreFlux dashboard). Alias bare imports to THIS app's
// node_modules so there is a single React/Layer instance, and allow Vite to
// read files outside /app/frontend.
const here = path.resolve(__dirname);
const nm = (p) => path.resolve(here, 'node_modules', p);

export default defineConfig({
  base: '/',
  plugins: [react()],
  resolve: {
    alias: {
      react: nm('react'),
      'react-dom': nm('react-dom'),
      'react-router-dom': nm('react-router-dom'),
      'lucide-react': nm('lucide-react'),
      '@layerfi/components': nm('@layerfi/components'),
    },
    dedupe: ['react', 'react-dom', 'react-router-dom'],
  },
  server: {
    host: true,
    port: 3000,
    strictPort: true,
    allowedHosts: true,
    fs: { allow: [path.resolve(here, '..')] },
    hmr: { clientPort: 443 },
  },
});
