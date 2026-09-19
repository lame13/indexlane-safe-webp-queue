import { defineConfig } from 'vite';

// An ES module entry for the WordPress admin page; no public UI or CDN.
export default defineConfig({
  base: './',
  worker: { format: 'es' },
  build: {
    target: 'es2022',
    assetsInlineLimit: 0,
    manifest: true,
    license: true,
    rolldownOptions: {
      input: 'src/index.ts',
      preserveEntrySignatures: 'strict',
      output: { entryFileNames: 'safewebp-browser.js' },
    },
  },
});
