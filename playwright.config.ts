import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/playwright',
  outputDir: './tests/playwright/results',
  // Resolves the lesson fixtures from the live catalogue and publishes them as env vars, so no
  // spec has to name a lesson id or code that only existed in one database. See the file.
  globalSetup: './tests/playwright/support/global-setup.ts',
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
      // Motion only. Real GPU compositing, so CSS transform transitions actually advance —
      // see the note on the chromium project's SwiftShader flags. Nothing here needs WebGL.
      name: 'motion',
      testMatch: /ken-burns\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: { args: ['--mute-audio'] },
      },
    },
    {
      name: 'chromium',
      testIgnore: /ken-burns\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: [
            // SwiftShader is here for WebGL (the map and the 3D stage), and it costs something:
            // under it, compositor-driven CSS transform transitions do not tick — they snap to
            // their end frame and sit there. A Ken Burns drift therefore reads as "frozen" in
            // every test run while being perfectly fine in a real browser. Measured side by side:
            // plain Chromium walked 1.0988 → 1.0925 over 1.5s, these flags reported the identical
            // matrix four times. Anything asserting on motion must launch without them.
            '--enable-unsafe-swiftshader',
            '--use-gl=angle',
            '--use-angle=swiftshader',
            // Lesson specs press play, and a full suite is a lot of narration out loud on whoever's
            // machine is running it. Silence the browser rather than relying on remembering to mute.
            '--mute-audio',
          ],
        },
      },
    },
  ],
});
