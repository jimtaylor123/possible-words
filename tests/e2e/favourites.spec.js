import { test, expect } from '@playwright/test';

const login = async (page, email = `fave-e2e-${Date.now()}@example.com`) => {
    const response = await page.request.post('/testing/login', {
        form: { email, name: 'E2E User', is_admin: '0' },
    });
    expect(response.ok()).toBeTruthy();
};

test.describe('Favourites', () => {
    test('Popular is public while Favourites remains auth-gated', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('nav')).not.toContainText('Favourites');
        await expect(page.locator('nav')).toContainText('Popular');

        await page.getByRole('link', { name: 'Popular' }).click();
        await expect(page).toHaveURL(/\/popular$/);
        await expect(page.getByRole('heading', { name: 'Most Favourited Words' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Popular' })).toHaveAttribute('aria-current', 'page');
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

    test('a signed-in user can favourite a popular word without opening its detail page', async ({ page }) => {
        await login(page);

        await page.goto('/popular');
        const card = page.locator('a[href*="/words/"]').first();
        await card.waitFor({ state: 'visible' });

        await card.getByRole('button', { name: 'Add to favourites' }).click();

        await expect(page).toHaveURL(/\/popular$/);
        await expect(card.getByRole('button', { name: 'Remove from favourites' })).toBeVisible();
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
