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

        await expect(page.getByRole('heading', { name: 'Account', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Download your data' })).toHaveAttribute('href', /\/account\/export$/);

        await page.getByRole('button', { name: 'Delete account', exact: true }).click();
        const deleteButton = page.getByRole('button', { name: 'Permanently delete account' });
        await expect(deleteButton).toBeDisabled();

        const confirmationInput = page.locator('.n-modal input');
        await confirmationInput.fill('delete');
        await expect(deleteButton).toBeDisabled();

        await confirmationInput.fill('DELETE');
        await deleteButton.click();

        await expect(page.getByText('Your account has been deleted.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Continue with Google' })).toBeVisible();
    });
});
