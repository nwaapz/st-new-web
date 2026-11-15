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
        id: '/test2/sales/',
        name: 'StarTech فروشندگان',
        short_name: 'StarTech فروش',
        description: 'اپ فروش استارتک — نسخه وب با کش آفلاین',
        start_url: '/test2/sales/',
        scope: '/test2/sales/',
        display: 'standalone',
        display_override: ['standalone', 'minimal-ui'],
        orientation: 'portrait',
        dir: 'rtl',
        lang: 'fa',
        background_color: '#111111',
        theme_color: '#f5c518',
        prefer_related_applications: false,
        icons: [
          {
            src: 'icons/pwa-192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'icons/pwa-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'icons/pwa-512-maskable.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
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
