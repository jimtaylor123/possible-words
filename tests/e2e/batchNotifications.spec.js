import { test, expect } from './support/fixtures.js';

test('an existing batch notification opens only its words and keeps filters scoped', async ({ page, e2e }) => {
    const fixture = await e2e.setup('batch_notifications');
    await e2e.login(fixture.user.email);
    await page.goto('/');
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    await page.getByText('Five new Possible Words', { exact: true }).click();
    await expect(page).toHaveURL(/\?batch=\d+$/);
    await expect(page.getByRole('heading', { name: 'Words from this batch' })).toBeVisible();
    await expect(page.locator('.word-discovery-card')).toHaveCount(5);
    for (const word of fixture.words) await expect(page.getByTestId(`word-card-${word.slug}`)).toBeVisible();
    const search = page.getByRole('combobox', { name: 'Search words' });
    await search.fill(fixture.words[0].text);
    await expect(page.locator('.word-discovery-card')).toHaveCount(1);
    await expect(page).toHaveURL(/batch=/);
    await page.getByRole('button', { name: 'Show filters', exact: true }).click();
    await page.getByRole('button', { name: 'Hide filters', exact: true }).click();
    await expect(page.locator('.word-discovery-card')).toHaveCount(5);
    await expect(page).toHaveURL(/batch=/);
    await page.getByRole('link', { name: 'View all words', exact: true }).click();
    await expect(page).not.toHaveURL(/batch=/);
    await expect(page.getByRole('heading', { name: 'Find your next favourite word' })).toBeVisible();
});
