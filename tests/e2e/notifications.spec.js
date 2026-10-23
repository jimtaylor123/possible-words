import { test, expect } from './support/fixtures.js';
import { installNotificationAudioMock } from './support/browser-mocks.js';

test('shows, reads, clears, and receives deterministic notifications', async ({ page, e2e }) => {
    await installNotificationAudioMock(page);
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
    await expect(page.getByTestId('notification-badge')).toHaveText('1');
    await expect(page.getByTestId('notification-bell')).toHaveClass(/notification-bell-ring/);
    await expect.poll(() => page.evaluate(() => window.__e2eAudio.starts)).toBe(1);
});

test('opens a notification target and marks it read', async ({ page, e2e }) => {
    const fixture = await e2e.setup('notifications');
    await e2e.login(fixture.user.email);
    await page.goto('/');
    await page.getByRole('button', { name: 'Notifications' }).click();
    await page.getByText('Unread E2E notification').click();
    await expect(page).toHaveURL(new RegExp(`/words/${fixture.words[0].slug}$`));
    await page.getByRole('button', { name: 'Notifications' }).click();
    await expect(page.getByText('You are all caught up')).toBeVisible();
});
