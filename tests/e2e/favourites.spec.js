import { test, expect } from '@playwright/test';

test('user can see favourites link when logged in', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('nav')).toContainText('Favourites');
});
