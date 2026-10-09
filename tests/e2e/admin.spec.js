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

const openFirstWord = async (page) => {
    await page.goto('/words');
    const card = page.locator('[role="link"]').first();
    await card.waitFor({ state: 'visible' });
    await card.click();
};

test.describe('Admin area', () => {
    test('an admin can view the report queue', async ({ page }) => {
        await login(page, 'admin-e2e@example.com', true);

        await page.goto('/admin');
        await expect(page).toHaveURL(/\/admin$/);
        await expect(page.getByRole('heading', { name: 'Report queue' })).toBeVisible();
    });

    test('a regular user is blocked from the admin dashboard', async ({ page }) => {
        await login(page, 'user-e2e@example.com');

        await page.goto('/admin');
        await expect(page.getByText('Forbidden')).toBeVisible();
        await expect(page.getByText('Report queue')).not.toBeVisible();
    });

    test('the admin menu item is only offered to admins', async ({ page }) => {
        await login(page, 'admin-menu@example.com', true);

        await page.goto('/');
        await page.locator('button[aria-label^="Account menu"]').click();
        await expect(page.locator('.n-dropdown-option').filter({ hasText: 'Admin' })).toBeVisible();
    });

    test('an admin can filter, dismiss, and reopen a submitted report', async ({ page }) => {
        await login(page, `admin-queue-reporter-${Date.now()}@example.com`);
        await openFirstWord(page);
        await page.getByRole('button', { name: 'Report this word' }).click();
        await page.locator('#report-reason').click();
        await page.getByText('This word is offensive or obscene', { exact: true }).last().click();
        await page.getByRole('button', { name: 'Submit report' }).click();
        await expect(page.getByRole('status')).toHaveText('Report submitted.');

        await login(page, `admin-queue-${Date.now()}@example.com`, true);
        await page.goto('/admin');
        await page.getByLabel('Report type').click();
        await page.getByText('Offensive word', { exact: true }).last().click();
        await expect(page.getByText('Offensive word', { exact: true }).first()).toBeVisible();

        await page.getByRole('button', { name: 'Review or dismiss' }).first().click();
        await page.getByLabel('Review action').click();
        await page.getByText('Dismiss', { exact: true }).last().click();
        await page.getByLabel('Internal note').fill('Not actionable.');
        await page.getByRole('button', { name: 'dismissed' }).click();
        await expect(page.getByRole('status')).toHaveText('Report dismissed.');

        await page.getByLabel('Lifecycle status').click();
        await page.getByText('Dismissed', { exact: true }).last().click();
        await expect(page.getByText('Not actionable.')).toBeVisible();
        await page.getByRole('button', { name: 'Reopen' }).first().click();
        await page.getByLabel('Internal note').fill('Needs another look.');
        await page.getByRole('button', { name: 'reopened' }).click();
        await expect(page.getByRole('status')).toHaveText('Report reopened.');
    });
});
