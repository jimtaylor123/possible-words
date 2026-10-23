import { test, expect } from './support/fixtures.js';

const openWord = async (page, fixture) => page.goto(`/words/${fixture.words[0].slug}`);

test('definition modal validates blank, boundaries, unicode, categories, and retains drafts', async ({ page, e2e }) => {
    const fixture = await e2e.setup('definitions');
    await e2e.login(fixture.user.email);
    await openWord(page, fixture);
    await page.getByRole('button', { name: 'Add a definition' }).click();
    const input = page.getByRole('textbox', { name: /What does this word mean/ });
    await page.getByRole('button', { name: 'Submit Definition' }).click();
    await expect(page.getByRole('alert')).toHaveText('Please enter a definition.');
    const oversized = '😀'.repeat(1001);
    await input.fill(oversized);
    await page.getByRole('button', { name: 'Submit Definition' }).click();
    await expect(input).toHaveValue(oversized);
    await input.fill('A deterministic verb');
    await page.getByLabel('Part of speech').click();
    await page.getByText('Verb', { exact: true }).last().click();
    await page.getByRole('button', { name: 'Submit Definition' }).click();
    await expect(page.getByText('A deterministic verb')).toBeVisible();
    await expect(page.getByText('Verb', { exact: true }).first()).toBeVisible();
});

test('definition action access and votes follow visible ownership state', async ({ page, e2e }) => {
    const fixture = await e2e.setup('definitions');
    await openWord(page, fixture);
    await expect(page.getByText('Want to add a definition or vote?')).toBeVisible();
    await e2e.login(fixture.user.email);
    await openWord(page, fixture);
    const live = page.locator('.border.rounded-lg.p-4').filter({ hasText: 'Deterministic live definition' });
    await live.getByRole('button', { name: 'Like definition' }).click();
    await expect(live.getByRole('button', { name: 'Remove like' })).toBeVisible();
    await live.getByRole('button', { name: 'Remove like' }).click();
    await expect(live.getByRole('button', { name: 'Like definition' })).toBeVisible();
    await expect(live.getByText('Remove', { exact: true })).toHaveCount(0);
    await expect(page.getByText('[deleted]')).toBeVisible();
    await expect(page.getByText('AI-generated')).toBeVisible();
});

test('word reports validate, cancel without retaining a draft, and show duplicate feedback', async ({ page, e2e }) => {
    const fixture = await e2e.setup('definitions');
    await e2e.login(fixture.user.email);
    await openWord(page, fixture);
    await page.getByRole('button', { name: 'Report this word' }).click();
    await expect(page.getByRole('button', { name: 'Submit report' })).toBeDisabled();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.getByRole('button', { name: 'Report this word' }).click();
    await expect(page.getByRole('textbox', { name: /Tell us more/ })).toHaveValue('');
    await page.locator('#report-reason').click();
    await page.getByText('This word is offensive or obscene', { exact: true }).last().click();
    await page.getByRole('textbox', { name: /Tell us more/ }).fill('Deterministic report context');
    await page.getByRole('button', { name: 'Submit report' }).click();
    await expect(page.getByRole('status')).toHaveText('Report submitted.');
    await page.getByRole('button', { name: 'Report this word' }).click();
    await page.locator('#report-reason').click();
    await page.getByText('This word is offensive or obscene', { exact: true }).last().click();
    await page.getByRole('button', { name: 'Submit report' }).click();
    await expect(page.getByText('You have already reported this item.', { exact: true }).first()).toBeVisible();
});
