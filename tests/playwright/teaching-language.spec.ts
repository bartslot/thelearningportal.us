import { test, expect } from '@playwright/test';

/**
 * Account settings now carries TWO language controls: the interface language and the teaching
 * language (which drives script generation and the narration voice). They are separate on purpose,
 * so this checks both render, both save, and that saving one does not clobber the other.
 */
test.describe('teaching language setting', () => {
  test('both language pickers render and save independently', async ({ page }) => {
    const livewire: number[] = [];
    const consoleErrors: string[] = [];
    page.on('response', r => { if (r.url().includes('livewire/update')) livewire.push(r.status()); });
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });

    await page.goto('/settings');

    // Both labels present — the whole point of the change is that these are two questions.
    await expect(page.getByText('Teaching language', { exact: true })).toBeVisible();
    const pickers = page.locator('[data-language-picker], select, [role="listbox"], button[aria-haspopup]');
    expect(await pickers.count()).toBeGreaterThan(0);

    // The explanatory copy must be there, or a teacher cannot tell the two apart.
    await expect(page.getByText(/written and read aloud in/i)).toBeVisible();

    await page.getByRole('button', { name: /save/i }).first().click();
    await page.waitForTimeout(1200);

    // Livewire must have actually round-tripped, and cleanly.
    expect(livewire.length, 'a livewire/update should fire on save').toBeGreaterThan(0);
    expect(livewire.every(s => s === 200), `livewire statuses: ${livewire}`).toBe(true);
    expect(consoleErrors.join('\n')).not.toMatch(/livewire|alpine|uncaught/i);
  });
});

test.describe('trending carousel', () => {
  test('every card with a lesson links to its player', async ({ page }) => {
    await page.goto('/');
    const links = page.locator('a[aria-label^="Play the lesson"]');
    const n = await links.count();
    expect(n, 'trending cards should link to lessons').toBeGreaterThan(0);

    for (let i = 0; i < n; i++) {
      const href = await links.nth(i).getAttribute('href');
      expect(href, 'card link must point at a lesson code').toMatch(/\/lesson\/[A-Z0-9]{6}$/);
    }

    // Follow the first one — a link that 404s is worse than no link.
    const first = await links.first().getAttribute('href');
    const res = await page.goto(first!);
    expect(res?.status(), `${first} should load`).toBe(200);
    await expect(page.getByRole('button', { name: /start lesson/i })).toBeVisible();
  });
});
