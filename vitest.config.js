import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['resources/js/**/__tests__/**/*.test.js'],
  },
})
