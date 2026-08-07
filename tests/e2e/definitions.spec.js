import { test, expect } from '@playwright/test';

const login = async (page, email) => {
    const response = await page.request.post('/testing/login', {
        form: { email, name: 'E2E User', is_admin: '0' },
    });
    expect(response.ok()).toBeTruthy();
};

const logout = async (page) => {
    const response = await page.request.post('/testing/logout');
    expect(response.ok()).toBeTruthy();
};

const openFirstWord = async (page) => {
    await page.goto('/words');

    const card = page.locator('[role="link"]').first();
    await card.waitFor({ state: 'visible' });
    await card.click();
    await expect(page).toHaveURL(/\/words\/.+/);
};

test.describe('Adding definitions and voting', () => {
    test('a signed-in user can add a definition', async ({ page }) => {
        const phrase = `E2E definition added at ${Date.now()}`;
        await login(page, 'author-e2e@example.com');

        await openFirstWord(page);

        const textarea = page.locator('textarea[placeholder="What does this word mean?"]');
        await textarea.fill(phrase);
        await page.getByRole('button', { name: 'Submit Definition' }).click();

        await expect(page.getByText(phrase)).toBeVisible();
        await expect(page.getByText(phrase).locator('..').getByText(/1 like$/)).toBeVisible();
    });

    test('a second user liking a definition increments its count', async ({ page }) => {
        const phrase = `E2E definition to like ${Date.now()}`;
        await login(page, 'author-two@example.com');

        await openFirstWord(page);

        const textarea = page.locator('textarea[placeholder="What does this word mean?"]');
        await textarea.fill(phrase);
        await page.getByRole('button', { name: 'Submit Definition' }).click();

        await expect(page.getByText(phrase)).toBeVisible();
        const wordUrl = page.url();

        await logout(page);
        await login(page, 'voter-e2e@example.com');

        await page.goto(wordUrl);

        const definition = page.locator('.border.rounded-lg.p-4').filter({ hasText: phrase });
        await expect(definition).toBeVisible();

        await definition.getByRole('button').click();

        await expect(definition.getByText(/2 likes$/)).toBeVisible();
    });

    test('a guest cannot add a definition', async ({ page }) => {
        await openFirstWord(page);

        await expect(page.locator('textarea[placeholder="What does this word mean?"]')).toHaveCount(0);
        await expect(page.getByText('Want to add a definition or vote?')).toBeVisible();
    });
});
