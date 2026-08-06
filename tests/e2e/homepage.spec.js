import { test, expect } from '@playwright/test';

test('homepage loads and shows generated words', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('h1')).toHaveText('Discover Available Words');
    await expect(page.locator('[role="link"]').first()).toBeVisible();
    await expect(page.locator('input[placeholder="Search words..."]')).toBeVisible();
});
