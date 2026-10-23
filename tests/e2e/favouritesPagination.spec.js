import { test, expect } from './support/fixtures.js';

test('fixture favourites and popular lists expose deterministic pagination and ranking', async ({ page, e2e }) => {
    const fixture = await e2e.setup('favourites');
    await e2e.login(fixture.user.email);
    await page.goto('/favourites');
    await expect(page.getByRole('heading', { name: 'Your Favourite Words' })).toBeVisible();
    await expect(page.getByText(fixture.words[0].text)).toBeVisible();
    await page.locator('.n-pagination-item').filter({ hasText: '2' }).click();
    await expect(page).toHaveURL(/page=2/);
    await page.goto('/popular');
    await expect(page.getByRole('heading', { name: 'Most Favourited Words' })).toBeVisible();
    await expect(page.getByText(/1 favourite/).first()).toBeVisible();
});
