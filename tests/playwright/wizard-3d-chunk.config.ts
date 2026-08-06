import { defineConfig, devices } from '@playwright/test'

/**
 * Runs wizard-3d-chunk-blocking.spec.ts twice, on the same machine, differing in ONE thing:
 * whether Chromium is forced onto SwiftShader (software WebGL).
 *
 * The default suite launches with `--use-angle=swiftshader`, and the 3D stage this spec is about
 * builds a WebGL context. A multi-second main-thread stall measured under a software rasteriser
 * could easily be the rasteriser, not the app — so the same measurement is taken with real GPU
 * compositing. If both runs stall for the same duration, the harness is not the cause.
 */
export default defineConfig({
    testDir: '.',
    testMatch: /wizard-3d-chunk-blocking\.spec\.ts/,
    outputDir: './results-3d-chunk-run',
    globalSetup: './support/global-setup.ts',
    timeout: 120_000,
    retries: 0,
    reporter: [['list']],
    workers: 1,
    use: {
        baseURL: process.env.PW_BASE_URL ?? 'http://localhost:8000',
        headless: true,
        javaScriptEnabled: true,
    },
    projects: [
        {
            name: 'gpu',
            use: { ...devices['Desktop Chrome'], launchOptions: { args: ['--mute-audio'] } },
        },
        {
            name: 'swiftshader',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: {
                    args: ['--enable-unsafe-swiftshader', '--use-gl=angle', '--use-angle=swiftshader', '--mute-audio'],
                },
            },
        },
    ],
})
