import { test, expect } from '@playwright/test';

test('favourites link is hidden from guests (auth-gated)', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('nav')).not.toContainText('Favourites');
});

test('guests browsing to the favourites route are sent to Google sign-in', async ({ page }) => {
    await page.goto('/favourites');
    await expect(page).toHaveURL(/accounts\.google\.com/);
});
