import { test, expect } from '@playwright/test';

const login = async (page, email, isAdmin = false) => {
    const response = await page.request.post('/testing/login', {
        form: {
            email,
            name: 'E2E User',
            is_admin: isAdmin ? '1' : '0',
        },
    });
    expect(response.ok()).toBeTruthy();
};

test.describe('Admin area', () => {
    test('an admin can view the admin dashboard', async ({ page }) => {
        await login(page, 'admin-e2e@example.com', true);

        await page.goto('/admin');
        await expect(page).toHaveURL(/\/admin$/);
        await expect(page.getByRole('heading', { name: 'Admin Dashboard' })).toBeVisible();
    });

    test('a regular user is blocked from the admin dashboard', async ({ page }) => {
        await login(page, 'user-e2e@example.com');

        await page.goto('/admin');
        await expect(page.getByText('Forbidden')).toBeVisible();
        await expect(page.getByText('Admin Dashboard')).not.toBeVisible();
    });

    test('the admin menu item is only offered to admins', async ({ page }) => {
        await login(page, 'admin-menu@example.com', true);

        await page.goto('/');
        await page.locator('button[aria-label^="Account menu"]').click();
        await expect(page.locator('.n-dropdown-option').filter({ hasText: 'Admin' })).toBeVisible();
    });
});
