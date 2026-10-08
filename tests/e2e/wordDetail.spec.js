import { test, expect } from '@playwright/test';

const login = async (page, email) => {
    const response = await page.request.post('/testing/login', {
        form: { email, name: 'E2E User', is_admin: '0' },
    });
    expect(response.ok()).toBeTruthy();
};

const openFirstWord = async (page) => {
    await page.goto('/words');

    const card = page.locator('[role="link"]').first();
    await card.waitFor({ state: 'visible' });
    await card.click();
    await expect(page).toHaveURL(/\/words\/.+/);
};

test.describe('Word detail page', () => {
    test('navigating from the list opens a word detail page', async ({ page }) => {
        await page.goto('/words');

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        const wordText = (await card.locator('.text-xl').first().textContent()).trim();
        await card.click();

        await expect(page.locator('h1')).toHaveText(wordText);
        await expect(page).toHaveURL(/\/words\/.+/);
    });

    test('word detail shows the definitions section', async ({ page }) => {
        await page.goto('/words');

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        await card.click();

        const section = page.locator('h2', { hasText: /Definitions/i });
        await expect(section).toBeVisible();
    });

    test('a guest is offered a sign-in prompt to contribute', async ({ page }) => {
        await page.goto('/words');

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        await card.click();

        await expect(page.getByText('Want to add a definition or vote?')).toBeVisible();
        await expect(page.getByRole('main').getByRole('button', { name: /Continue with Google/i })).toBeVisible();
    });

    test('guests cannot report words or definitions', async ({ page }) => {
        await openFirstWord(page);

        await expect(page.getByRole('button', { name: 'Report this word' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Report this definition' })).toHaveCount(0);
    });

    test('a signed-in user can report an unfresh word and sees duplicate feedback', async ({ page }) => {
        await login(page, `word-report-e2e-${Date.now()}@example.com`);
        await openFirstWord(page);

        await page.getByRole('button', { name: 'Report this word' }).click();
        await page.locator('#report-reason').click();
        await page.getByText('This word is not fresh', { exact: true }).last().click();
        await page.getByRole('button', { name: 'Submit report' }).click();

        await expect(page.getByRole('status')).toHaveText('Report submitted.');

        await page.getByRole('button', { name: 'Report this word' }).click();
        await page.getByRole('button', { name: 'Submit report' }).click();

        await expect(page.getByRole('dialog').getByRole('alert')).toHaveText('You have already reported this item.');
    });

    test('a signed-in user can report an offensive or obscene word', async ({ page }) => {
        await login(page, `offensive-word-report-e2e-${Date.now()}@example.com`);
        await openFirstWord(page);

        await page.getByRole('button', { name: 'Report this word' }).click();
        await page.locator('#report-reason').click();
        await expect(page.getByText('This word is not fresh', { exact: true }).last()).toBeVisible();
        await expect(page.getByText('This word is offensive or obscene', { exact: true }).last()).toBeVisible();
        await page.getByText('This word is offensive or obscene', { exact: true }).last().click();
        await page.locator('#report-explanation textarea').fill('This word is obscene.');
        await page.getByRole('button', { name: 'Submit report' }).click();

        await expect(page.getByRole('status')).toHaveText('Report submitted.');
    });

test('a signed-in user can report a definition and sees duplicate feedback', async ({ page }) => {
        const definitionText = `Offensive definition report ${Date.now()}`;
        await login(page, `definition-report-e2e-${Date.now()}@example.com`);
        await openFirstWord(page);

        await page.locator('textarea[placeholder="What does this word mean?"]').fill(definitionText);
        await page.getByRole('button', { name: 'Submit Definition' }).click();

        const definition = page.locator('.border.rounded-lg.p-4').filter({ hasText: definitionText });
        await expect(definition).toBeVisible();
        await definition.getByRole('button', { name: 'Report this definition' }).click();
        await page.locator('#report-reason').click();
        await expect(page.getByText('This definition is offensive or obscene', { exact: true }).last()).toBeVisible();
        await page.getByText('This definition is offensive or obscene', { exact: true }).last().click();
        await page.locator('#report-explanation textarea').fill('This definition is obscene.');
        await page.getByRole('button', { name: 'Submit report' }).click();

        await expect(page.getByRole('status')).toHaveText('Report submitted.');

        await definition.getByRole('button', { name: 'Report this definition' }).click();
        await page.locator('#report-reason').click();
        await page.getByText('This definition is offensive or obscene', { exact: true }).last().click();
        await page.getByRole('button', { name: 'Submit report' }).click();

        await expect(page.getByRole('dialog').getByRole('alert')).toHaveText('You have already reported this item.');
    });
