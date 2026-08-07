import { test, expect } from '@playwright/test';

const login = async (page, email = `fave-e2e-${Date.now()}@example.com`) => {
    const response = await page.request.post('/testing/login', {
        form: { email, name: 'E2E User', is_admin: '0' },
    });
    expect(response.ok()).toBeTruthy();
};

test.describe('Favourites', () => {
    test('favourites link is hidden from guests (auth-gated)', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('nav')).not.toContainText('Favourites');
    });

    test('guests browsing to the favourites route are sent to Google sign-in', async ({ page }) => {
        await page.goto('/favourites');
        await expect(page).toHaveURL(/accounts\.google\.com/);
    });

    test('a signed-in user can favourite a word and see it on the favourites page', async ({ page }) => {
        await login(page);

        await page.goto('/words');
        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        const wordText = (await card.locator('.text-xl').first().textContent()).trim();
        await card.click();

        await expect(page).toHaveURL(/\/words\/.+/);
        await page.getByRole('button', { name: 'Add to favourites' }).click();

        await page.goto('/favourites');
        await expect(page.getByRole('heading', { name: 'Your Favourite Words' })).toBeVisible();
        await expect(page.getByText(wordText)).toBeVisible();
    });

    test('a signed-in user can unfavourite a word', async ({ page }) => {
        await login(page);

        await page.goto('/words');
        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        const wordText = (await card.locator('.text-xl').first().textContent()).trim();
        await card.click();

        await page.getByRole('button', { name: 'Add to favourites' }).click();
        await page.getByRole('button', { name: 'Remove from favourites' }).click();

        await page.goto('/favourites');
        await expect(page.getByText(wordText)).toHaveCount(0);
    });
});
