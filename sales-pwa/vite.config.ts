import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  base: '/test2/sales/',
  plugins: [
    react(),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      registerType: 'autoUpdate',
      injectRegister: false,
      includeAssets: [],
      manifest: {
        name: 'StarTech فروشندگان',
        short_name: 'StarTech فروش',
        description: 'اپ فروش استارتک — نسخه وب با کش آفلاین',
        start_url: '/test2/sales/',
        scope: '/test2/sales/',
        display: 'standalone',
        orientation: 'portrait',
        dir: 'rtl',
        lang: 'fa',
        background_color: '#111111',
        theme_color: '#f5c518',
        icons: [
          {
            src: '/test2/images/logo.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: '/test2/images/logo.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any maskable',
          },
        ],
      },
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2,webmanifest}'],
      },
    }),
  ],
  build: {
    outDir: '../sales',
    emptyOutDir: true,
  },
});
