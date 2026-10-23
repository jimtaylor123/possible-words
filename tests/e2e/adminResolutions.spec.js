import { test, expect } from './support/fixtures.js';

test('an administrator validates, dismisses, and records report review history', async ({ page, e2e }) => {
    await e2e.setup('reports');
    await e2e.login(`e2e-${e2e.namespace}-admin@example.test`, true);
    await page.goto('/admin');
    await expect(page.getByText('Deterministic fixture report')).toBeVisible();
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('textbox').last().fill('Not actionable in this fixture');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.goto('/admin?status=dismissed');
    await expect(page.getByText('Not actionable in this fixture').first()).toBeVisible();
});
