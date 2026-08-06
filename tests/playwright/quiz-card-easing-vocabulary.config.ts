import { defineConfig, devices } from '@playwright/test';

/**
 * Own config, own output dir — this spec measures DECLARED CSS curves and one real entrance, and
 * must not share fixtures or an output directory with a concurrent run of the main suite.
 *
 * No SwiftShader: under `--use-angle=swiftshader` compositor-driven CSS animations snap to their
 * end frame, and a card that never animates cannot have its entrance curve read from the
 * Animation domain at all.
 *
 *   npx playwright test --config=tests/playwright/quiz-card-easing-vocabulary.config.ts
 */
export default defineConfig({
  testDir: '.',
  testMatch: /quiz-card-easing-vocabulary\.spec\.ts/,
  outputDir: './results-quiz-card-easing',
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
