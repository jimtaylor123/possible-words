import { test, expect } from '@playwright/test';

test.describe('Word detail page', () => {
    test('navigating from the list opens a word detail page', async ({ page }) => {
        await page.goto('/words');

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        const wordText = (await card.locator('.text-xl').first().textContent()).trim();
        await card.click();

        await expect(page.locator('h1')).toHaveText(wordText);
        await expect(page).toHaveURL(/\/words\/.+/);
    });

    test('word detail shows the definitions section', async ({ page }) => {
        await page.goto('/words');

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        await card.click();

        const section = page.locator('h2', { hasText: /Definitions/i });
        await expect(section).toBeVisible();
    });

    test('a guest is offered a sign-in prompt to contribute', async ({ page }) => {
        await page.goto('/words');

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        await card.click();

        await expect(page.getByText('Want to add a definition or vote?')).toBeVisible();
        await expect(page.getByRole('main').getByRole('button', { name: /Continue with Google/i })).toBeVisible();
    });
});
