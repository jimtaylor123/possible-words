import { test, expect } from '@playwright/test';

test('homepage loads and shows generated words', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Real sounds.Unreal possibilities.');
    await expect(page.getByRole('heading', { level: 2, name: 'Find your next favourite word' })).toBeVisible();
    await expect(page.locator('[role="link"]').first()).toBeVisible();
    await expect(page.locator('input[placeholder="Search words..."]')).toBeVisible();
});
