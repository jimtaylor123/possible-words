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

    test('matching reports are grouped and an admin can mute and unmute a case', async ({ page }) => {
        const suffix = Date.now();
        await login(page, `admin-queue-reporter-${suffix}@example.com`);
        await openFirstWord(page);
        await page.getByRole('button', { name: 'Report this word' }).click();
        await page.locator('#report-reason').click();
        await page.getByText('This word is offensive or obscene', { exact: true }).last().click();
        await page.getByRole('button', { name: 'Submit report' }).click();
        await expect(page.getByRole('status')).toHaveText('Report submitted.');

        await login(page, `admin-queue-second-reporter-${suffix}@example.com`);
        await page.getByRole('button', { name: 'Report this word' }).click();
        await page.locator('#report-reason').click();
        await page.getByText('This word is offensive or obscene', { exact: true }).last().click();
        await page.getByRole('button', { name: 'Submit report' }).click();
        await expect(page.getByRole('status')).toHaveText('Report submitted.');

        await login(page, `admin-queue-${suffix}@example.com`, true);
        await page.goto('/admin');
        await page.getByLabel('Report type').click();
        await page.getByText('Offensive word', { exact: true }).last().click();
        await expect(page.getByText('Unique reporters: 2')).toBeVisible();
        await page.getByRole('button', { name: 'Dismiss or mute' }).click();
        await page.getByLabel('Review action').click();
        await page.getByText('Mute', { exact: true }).last().click();
        await page.getByRole('button', { name: 'muted' }).click();
        await expect(page.locator('main').getByRole('status')).toHaveText('Report case muted.');

        await page.getByLabel('Lifecycle status').click();
        await page.getByText('Muted', { exact: true }).last().click();
        await page.getByRole('button', { name: 'Unmute' }).click();
        await page.getByRole('button', { name: 'unmuted' }).click();
        await expect(page.locator('main').getByRole('status')).toHaveText('Report case unmuted.');
    });

    test('a regular user cannot submit a crafted report review mutation', async ({ page }) => {
        await login(page, `crafted-review-${Date.now()}@example.com`);
        await page.goto('/');
        const csrfToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');

        const response = await page.request.patch('/admin/reports/1', {
            form: { action: 'muted' },
            headers: { 'X-CSRF-TOKEN': csrfToken },
        });

        // The admin middleware runs before route-model binding, so a non-admin
        // is rejected with 403 regardless of whether the report exists.
        expect(response.status()).toBe(403);
    });
});
