import { test, expect } from './support/fixtures.js';

test('a fixture user can visit account controls, cancel deletion, and download an export', async ({ page, e2e }) => {
    const fixture = await e2e.setup();
    await e2e.login(fixture.user.email);
    await page.goto('/account');
    await expect(page.getByRole('heading', { name: 'Account', exact: true })).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download your data' }).click();
    expect((await download).suggestedFilename()).toBe('possiblewords-account-export.json');
    await page.getByRole('button', { name: 'Delete account' }).click();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
});
