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

    test('an admin can suspend and restore an account from user management', async ({ page, browser }) => {
        const suffix = `${Date.now()}-${test.info().parallelIndex}`;
        const targetEmail = `suspended-e2e-${suffix}@example.com`;
        const targetContext = await browser.newContext();
        const targetPage = await targetContext.newPage();

        await login(targetPage, targetEmail);
        await targetPage.goto('/account');
        await expect(targetPage).toHaveURL(/\/account$/);

        await login(page, `admin-users-${suffix}@example.com`, true);
        await page.goto('/admin');
        await page.getByRole('link', { name: 'User management' }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);
        await page.locator('[aria-label="Search users"] input').fill(targetEmail);
        await page.getByRole('button', { name: 'Search' }).click();
        await expect(page.getByText(targetEmail)).toBeVisible();
        const targetCard = page.locator('article').filter({ hasText: targetEmail });
        await targetCard.getByRole('button', { name: 'Suspend account', exact: true }).click();
        await page.locator('[aria-label="Suspension reason"] textarea').fill('Repeated abusive reports');
        await page.getByRole('dialog').getByRole('button', { name: 'Suspend account', exact: true }).click();
        await expect(page.locator('main').getByRole('status')).toHaveText('Account suspended.');

        const blockedAccount = await targetPage.request.get('/account', { maxRedirects: 0 });
        expect(blockedAccount.status()).toBe(302);
        expect(blockedAccount.headers().location).toContain('/auth/google');
        const rejectedLogin = await targetPage.request.post('/testing/login', { form: { email: targetEmail } });
        expect(rejectedLogin.status()).toBe(403);

        await targetCard.getByRole('button', { name: 'Restore account' }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Restore account' }).click();
        await expect(page.locator('main').getByRole('status')).toHaveText('Account restored.');
        const restoredLogin = await targetPage.request.post('/testing/login', { form: { email: targetEmail } });
        expect(restoredLogin.ok()).toBeTruthy();
        await targetPage.goto('/account');
        await expect(targetPage).toHaveURL(/\/account$/);

        await targetContext.close();
    });

    test('matching reports are grouped and an admin can mute and unmute a case', async ({ page }) => {
        const suffix = `${Date.now()}-${test.info().parallelIndex}-${test.info().repeatEachIndex}`;
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
        await page.locator('.n-base-select-option').filter({ hasText: 'Offensive word' }).click();
        await expect(page.getByText('Unique reporters: 2')).toBeVisible();
        await page.getByRole('button', { name: 'Review' }).click();
        await page.getByRole('button', { name: 'Mute' }).click();
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('main').getByRole('status')).toHaveText('Report case muted.');

        await page.getByLabel('Lifecycle status').click();
        await page.locator('.n-base-select-option').filter({ hasText: 'Muted' }).click();
        await page.getByRole('button', { name: 'Unmute' }).click();
        await page.getByRole('button', { name: 'Save' }).click();
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

        // A crafted request cannot mutate a case; route binding may return 404
        // before the authorization middleware for an unknown case.
        expect([403, 404]).toContain(response.status());
    });
});
