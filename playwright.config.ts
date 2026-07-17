import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/playwright',
  outputDir: './tests/playwright/results',
  timeout: 60_000,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'tests/playwright/report', open: 'never' }]],

  use: {
    baseURL: process.env.PW_BASE_URL ?? 'http://localhost:8000',
    headless: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'on-first-retry',
    // Livewire needs JS — never disable
    javaScriptEnabled: true,
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: [
            '--enable-unsafe-swiftshader',
            '--use-gl=angle',
            '--use-angle=swiftshader',
          ],
        },
      },
    },
  ],
});
