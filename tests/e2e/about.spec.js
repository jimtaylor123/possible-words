import { test, expect } from '@playwright/test';

test.describe('About page', () => {
    test('about page renders the brand story', async ({ page }) => {
        await page.goto('/about');

        await expect(page).toHaveURL(/\/about$/);
        await expect(page.getByRole('heading', { name: 'What Is PossibleWords?' })).toBeVisible();
        await expect(page.getByText(/Unclaimed territory/)).toBeVisible();
    });

    test('navigation links reach the about page', async ({ page }) => {
        await page.goto('/');

        const aboutLink = page.locator('nav').getByRole('link', { name: /About/i });
        await aboutLink.click();

        await expect(page).toHaveURL(/\/about$/);
    });
});
