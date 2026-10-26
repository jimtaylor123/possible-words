import { test, expect } from './support/fixtures.js';

test.describe('admin user management', () => {
    test('an administrator searches, inspects, suspends, and restores fixture users', async ({ page, e2e }) => {
        const fixture = await e2e.setup('users');
        await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
        await page.goto(`/admin?tab=users&search=${encodeURIComponent(fixture.users[0].email)}&sort=email&direction=asc`);
        await expect(page.getByText(fixture.users[0].email, { exact: true })).toBeVisible();
        const row = page.locator('.n-data-table-tr').filter({ hasText: fixture.users[0].email });

        await row.getByRole('button', { name: `Suspend ${fixture.users[0].name}'s account` }).click();
        await page.getByRole('textbox', { name: 'Suspension reason' }).fill('Fixture moderation reason');
        await page.getByRole('button', { name: 'Suspend account', exact: true }).last().click();
        await expect(row.getByText(/Suspended/)).toBeVisible();

        await row.getByRole('button', { name: 'Restore' }).click();
        await page.getByRole('button', { name: 'Restore account', exact: true }).last().click();
        await expect(row.getByRole('button', { name: `Suspend ${fixture.users[0].name}'s account` })).toBeVisible();
    });

    test('the suspension form rejects a blank reason without a request', async ({ page, e2e }) => {
        const fixture = await e2e.setup('users');
        await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
        await page.goto(`/admin?tab=users&search=${encodeURIComponent(fixture.users[0].email)}`);
        await page.getByRole('button', { name: `Suspend ${fixture.users[0].name}'s account` }).click();
        await page.getByRole('button', { name: 'Suspend account', exact: true }).last().click();
        await expect(page.getByRole('alert')).toHaveText('A suspension reason is required.');
    });

    test('shows sortable pages, empty filters, activity details, and self guard', async ({ page, e2e }) => {
        const fixture = await e2e.setup('users');
        const adminEmail = `e2e-${e2e.namespace}-admin@example.test`;
        await e2e.login(adminEmail, true);
        await page.goto('/admin?tab=users&sort=name&direction=asc&page=2');
        expect(Object.fromEntries(new URL(page.url()).searchParams)).toEqual(expect.objectContaining({ sort: 'name', direction: 'asc', page: '2' }));
        await expect(page.getByText('Inactive', { exact: true }).first()).toBeVisible();
        await page.goto('/admin?tab=users&search=no-such-fixture-account');
        await expect(page.getByText('No Data', { exact: true })).toBeVisible();
        await page.goto(`/admin?tab=users&search=${encodeURIComponent(adminEmail)}`);
        await expect(page.getByText('Current user')).toBeVisible();
    });

    test('a regular user sees an authorization response instead of user management', async ({ page, e2e }) => {
        const fixture = await e2e.setup('users');
        await e2e.login(fixture.user.email);
        const response = await page.goto('/admin?tab=users');
        expect(response.status()).toBe(403);
    });
});
