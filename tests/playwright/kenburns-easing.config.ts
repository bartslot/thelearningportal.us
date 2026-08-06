import { defineConfig, devices } from '@playwright/test';

/**
 * Own config, own output dir — the Ken Burns easing question needs a REAL compositor (no
 * SwiftShader, which snaps transform transitions to their end frame) and must not share fixtures
 * with a concurrent run of the main suite.
 */
export default defineConfig({
  testDir: '.',
  testMatch: /kenburns-easing\.spec\.ts/,
  outputDir: './results-kenburns-easing',
  timeout: 120_000,
  retries: 0,
  reporter: [['list']],
  use: {
    ...devices['Desktop Chrome'],
    baseURL: process.env.PW_BASE_URL ?? 'http://127.0.0.1:8000',
    headless: true,
    screenshot: 'only-on-failure',
    launchOptions: { args: ['--mute-audio'] },
  },
});
