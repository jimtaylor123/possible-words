import { test, expect } from './support/fixtures.js';

test('shows, reads, clears, and receives deterministic notifications', async ({ page, e2e }) => {
    const fixture = await e2e.setup('notifications');
    await e2e.login(fixture.user.email);
    await page.goto('/');
    await page.getByRole('button', { name: 'Notifications' }).click();
    await expect(page.getByText('Unread E2E notification')).toBeVisible();
    await expect(page.getByText('1 unread')).toBeVisible();

    await page.getByRole('button', { name: 'Read all' }).click();
    await expect(page.getByText('You are all caught up')).toBeVisible();
    await page.getByRole('button', { name: 'Clear read' }).click();
    await expect(page.getByText('No notifications yet.')).toBeVisible();

    await page.evaluate((userId) => {
        const listener = window.__e2eEchoListeners.get(`App.Models.User.${userId}:notification`);
        listener?.({
            id: 'e2e-realtime-notification',
            data: { title: 'Realtime E2E notification', body: 'Mocked Echo callback' },
            created_at: new Date().toISOString(),
        });
    }, fixture.user.id);
    await expect(page.getByText('Realtime E2E notification')).toBeVisible();
});
