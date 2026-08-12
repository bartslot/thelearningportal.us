import { Page } from '@playwright/test';

/**
 * Sign in as the seeded Playwright teacher.
 *
 * Extracted from timemap.spec.ts so the visual suite runs the same login rather than a second copy
 * that drifts. When APP_AUTO_LOGIN=true (local dev) every request is already authenticated, so
 * /login redirects away immediately and there is no form to fill — detect that and skip.
 *
 * Seed, if it has never been run here:
 *   php artisan tinker --execute="App\Models\User::create(['name'=>'Playwright Teacher',
 *     'email'=>'teacher@playwright.test','password'=>bcrypt('playwright123'),'role'=>'teacher']);"
 */
export async function loginAsTeacher(page: Page): Promise<void> {
  await page.goto('/login');
  if (!page.url().includes('/login')) return;
  await page.fill('#email', 'teacher@playwright.test');
  await page.fill('#password', 'playwright123');
  await page.click('button[type=submit]');
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 10_000 });
}
