import { test, expect } from './support/fixtures.js';

test('a fixture user can visit account controls, cancel deletion, and download an export', async ({ page, e2e }) => {
    const fixture = await e2e.setup();
    await e2e.login(fixture.user.email);
    await page.goto('/account');
    await expect(page.getByRole('heading', { name: 'Account', exact: true })).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download your data' }).click();
    expect((await download).suggestedFilename()).toBe('possiblewords-account-export.json');
    const exportResponse = await page.request.get('/account/export');
    expect(exportResponse.headers()['content-type']).toContain('application/json');
    expect(await exportResponse.json()).toEqual(expect.objectContaining({ profile: expect.objectContaining({ email: fixture.user.email }) }));
    await page.getByRole('button', { name: 'Delete account' }).click();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('notification preferences persist for normal and administrator accounts', async ({ page, e2e }) => {
    const fixture = await e2e.setup();
    await e2e.login(fixture.user.email);
    await page.goto('/account');
    await page.getByTestId('general-notification-preference').click();
    await page.getByText('Immediate', { exact: true }).last().click();
    await page.getByRole('button', { name: 'Save preferences' }).click();
    await expect(page.getByRole('status')).toHaveText('Notification preferences updated.');
    await page.reload();
    await expect(page.getByTestId('general-notification-preference')).toContainText('Immediate');

    await e2e.login(fixture.user.email, true);
    await page.goto('/account');
    await expect(page.getByTestId('admin-notification-preference')).toBeVisible();
    await page.getByTestId('admin-notification-preference').click();
    await page.getByText('Off', { exact: true }).last().click();
    await page.getByRole('button', { name: 'Save preferences' }).click();
    await page.reload();
    await expect(page.getByTestId('admin-notification-preference')).toContainText('Off');
});

test('logout and deletion end a fixture account session', async ({ page, e2e }) => {
    const fixture = await e2e.setup();
    await e2e.login(fixture.user.email);
    await page.goto('/');
    await page.getByRole('button', { name: /Account menu for/ }).click();
    await page.getByText('Log out', { exact: true }).click();
    await expect(page.getByRole('button', { name: 'Continue with Google' })).toBeVisible();

    await e2e.login(fixture.user.email);
    await page.goto('/account');
    await page.getByRole('button', { name: 'Delete account' }).click();
    const confirmation = page.getByRole('textbox', { name: 'Type DELETE to confirm' });
    await confirmation.fill('nope');
    await expect(page.getByRole('button', { name: 'Permanently delete account' })).toBeDisabled();
    await confirmation.fill('DELETE');
    await page.getByRole('button', { name: 'Permanently delete account' }).click();
    await expect(page.getByRole('button', { name: 'Continue with Google' })).toBeVisible();
    const protectedResponse = await page.request.get('/account', { maxRedirects: 0 });
    expect(protectedResponse.status()).toBe(302);
    expect(protectedResponse.headers().location).toContain('/auth/google');
});

test('protected routes and sign-in initiation stay local before OAuth', async ({ page }) => {
    const protectedResponse = await page.request.get('/account', { maxRedirects: 0 });
    expect(protectedResponse.status()).toBe(302);
    expect(protectedResponse.headers().location).toContain('/auth/google');
    await page.route('**/auth/google', route => route.abort());
    await page.goto('/');
    const request = page.waitForRequest(request => new URL(request.url()).pathname === '/auth/google');
    await page.getByRole('button', { name: 'Continue with Google' }).click();
    await request;
});
