import { defineConfig } from 'vite'
import { resolve } from 'node:path'

/**
 * A standalone server for the globe measurement harness.
 *
 * Separate from vite.config.js because that one is wired to laravel-vite-plugin and expects to be
 * driven by the PHP app. This one serves one page and the project's public/ directory, which is
 * where the relief map, the cloud field and the wind field live.
 */
export default defineConfig({
  root: resolve(import.meta.dirname, 'resources/js/timemap/__harness__'),
  publicDir: resolve(import.meta.dirname, 'public'),
  server: { port: 5198, strictPort: true },
})
