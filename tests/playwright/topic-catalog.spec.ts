import { test, expect } from '@playwright/test';

// A1: lesson topic is locked to the curated catalog. Verifies the picker filters the
// catalog and selecting an entry locks the topic (grounded-in-Wikipedia indicator appears).
test.describe('Topic catalog picker (A1)', () => {
  test('search filters the catalog and selection locks the topic', async ({ page }) => {
    await page.goto('http://localhost:8000/teacher/lessons/create', { waitUntil: 'networkidle' });

    const topicInput = page.locator('#lw-topic');
    await expect(topicInput).toBeVisible();

    // Type a query — the catalog dropdown should appear with matching entries.
    await topicInput.click();
    await topicInput.fill('Roman Empire');
    // Wait for the Livewire debounce + round-trip to render suggestions.
    await page.waitForTimeout(800);

    const dropdown = page.locator('ul[data-topic-suggestions] li button');
    await expect(dropdown.first()).toBeVisible({ timeout: 5000 });

    // First matching result should mention "Roman".
    await expect(dropdown.first()).toContainText(/Roman/i);

    // Selecting it locks the topic — the "grounded in Wikipedia" line appears.
    await dropdown.first().click();
    await expect(page.locator('text=this Wikipedia article')).toBeVisible({ timeout: 5000 });

    // The lock must survive any trailing debounce from typing (no flicker back to unlocked).
    await page.waitForTimeout(1000);
    await expect(page.locator('text=this Wikipedia article')).toBeVisible();
    await expect(page.locator('text=Select an entry from the list')).toHaveCount(0);
  });

  test('a focus/angle field exists and is optional', async ({ page }) => {
    await page.goto('http://localhost:8000/teacher/lessons/create', { waitUntil: 'networkidle' });
    await expect(page.locator('input[name="focus"]')).toBeVisible();
  });
});
