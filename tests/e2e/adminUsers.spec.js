import { test, expect } from './support/fixtures.js';

test.describe('admin user management', () => {
    test('an administrator searches, inspects, suspends, and restores fixture users', async ({ page, e2e }) => {
        const fixture = await e2e.setup('users');
        await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
        await page.goto(`/admin/users?search=${encodeURIComponent(fixture.users[0].email)}&sort=email&direction=asc`);
        await expect(page.getByRole('heading', { name: fixture.users[0].name, exact: true })).toBeVisible();
        const card = page.locator('article').filter({ has: page.getByRole('heading', { name: fixture.users[0].name, exact: true }) });
        await card.getByRole('button', { name: 'View details' }).click();
        await expect(page.getByLabel(`Report details for ${fixture.users[0].name}`)).toBeVisible();

        await card.getByRole('button', { name: 'Suspend account' }).click();
        await page.getByRole('textbox').last().fill('Fixture moderation reason');
        await page.getByRole('button', { name: 'Suspend account', exact: true }).last().click();
        await expect(page.getByText(/Suspended/)).toBeVisible();

        await page.getByRole('button', { name: 'Restore account' }).click();
        await page.getByRole('button', { name: 'Restore account', exact: true }).last().click();
        await expect(page.getByRole('button', { name: 'Suspend account' })).toBeVisible();
    });

    test('the suspension form rejects a blank reason without a request', async ({ page, e2e }) => {
        const fixture = await e2e.setup('users');
        await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
        await page.goto(`/admin/users?search=${encodeURIComponent(fixture.users[0].email)}`);
        await page.getByRole('button', { name: 'Suspend account' }).click();
        await page.getByRole('button', { name: 'Suspend account', exact: true }).last().click();
        await expect(page.getByRole('alert')).toHaveText('A suspension reason is required.');
    });
});
