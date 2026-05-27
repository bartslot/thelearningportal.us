import { test, expect } from '@playwright/test';

test('app loads and returns 200', async ({ page }) => {
  const response = await page.goto('/');
  expect(response?.status()).toBeLessThan(400);
});

test('wizard route is accessible', async ({ page }) => {
  const response = await page.goto('/login');
  expect(response?.status()).toBeLessThan(400);
  await expect(page).toHaveTitle(/.+/);
});
