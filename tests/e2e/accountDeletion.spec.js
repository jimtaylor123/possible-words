import { test, expect } from '@playwright/test';

const login = async (page, email = `account-e2e-${Date.now()}@example.com`) => {
    const response = await page.request.post('/testing/login', {
        form: { email, name: 'Account E2E User', is_admin: '0' },
    });
    expect(response.ok()).toBeTruthy();
};

test.describe('Account deletion', () => {
    test('a user can export data and permanently delete their account', async ({ page }) => {
        await login(page);
        await page.goto('/account');

        await expect(page.getByRole('heading', { name: 'Account' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Download your data' })).toBeVisible();

        const download = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Download your data' }).click(),
        ]);
        expect((await download[0].suggestedFilename())).toBe('possiblewords-account-export.json');

        await page.getByRole('button', { name: 'Delete account', exact: true }).click();
        const deleteButton = page.getByRole('button', { name: 'Permanently delete account' });
        await expect(deleteButton).toBeDisabled();

        await page.getByLabel('Type DELETE to confirm').fill('delete');
        await expect(deleteButton).toBeDisabled();

        await page.getByLabel('Type DELETE to confirm').fill('DELETE');
        await deleteButton.click();

        await expect(page.getByText('Your account has been deleted.')).toBeVisible();
        await expect(page.getByRole('button', { name: /sign in with google/i })).toBeVisible();
    });
});
