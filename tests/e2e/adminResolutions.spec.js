import { test, expect } from './support/fixtures.js';

test('an administrator validates, dismisses, and records report review history', async ({ page, e2e }) => {
    await e2e.setup('reports');
    await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
    await page.goto('/admin');
    await expect(page.getByText('Deterministic fixture report')).toBeVisible();
    const reportCard = page.locator('article').filter({ hasText: 'Deterministic fixture report' });
    await reportCard.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('textbox').last().fill('Not actionable in this fixture');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.goto('/admin?status=dismissed');
    await expect(page.getByText('Not actionable in this fixture').first()).toBeVisible();
});

test('an administrator mutes, unmutes, and removes reported definition content', async ({ page, e2e }) => {
    const fixture = await e2e.setup('reports');
    const adminEmail = `e2e-${e2e.namespace}-admin@example.test`;
    await e2e.login(adminEmail, true);
    await page.goto('/admin');
    const wordCard = page.locator('article').filter({ hasText: 'Deterministic fixture report' });
    await wordCard.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'Mute' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Report case muted.', { exact: true }).first()).toBeVisible();
    await page.goto('/admin?status=muted');
    await page.getByRole('button', { name: 'Unmute' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Report case unmuted.', { exact: true }).first()).toBeVisible();

    await page.goto('/admin?reason=definition_offensive&status=open');
    const definitionCard = page.locator('article').filter({ hasText: 'Deterministic definition report' });
    await definitionCard.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'Remove content' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Report case removed.', { exact: true }).first()).toBeVisible();

    await e2e.login(`e2e-${e2e.namespace}-reporter@example.test`);
    await page.goto('/');
    await page.getByRole('button', { name: 'Notifications' }).click();
    await expect(page.getByText('Reported content removed')).toBeVisible();
    await page.goto(`/words/${fixture.words[0].slug}`);
    await expect(page.getByText('[deleted]')).toBeVisible();
});

test('filtered queues render an explicit empty state', async ({ page, e2e }) => {
    await e2e.setup('reports');
    await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
    await page.goto('/admin?reason=word_unfresh&status=open');
    await expect(page.getByText('No report cases match these filters.')).toBeVisible();
});

test('an administrator removes reported word content and reporters see the outcome', async ({ page, e2e }) => {
    const fixture = await e2e.setup('reports');
    await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
    await page.goto('/admin?reason=word_offensive&status=open');
    const card = page.locator('article').filter({ hasText: 'Deterministic fixture report' });
    await card.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'Remove content' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Report case removed.', { exact: true }).first()).toBeVisible();
    await e2e.login(`e2e-${e2e.namespace}-reporter@example.test`);
    await page.goto('/');
    await page.getByRole('button', { name: 'Notifications' }).click();
    await expect(page.getByText('Reported content removed')).toBeVisible();
    const removed = await page.request.get(`/words/${fixture.words[0].slug}`);
    expect(removed.status()).toBe(404);
});
