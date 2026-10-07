import { test, expect } from '@playwright/test';

// Cards are identified by their author rather than by their text: once a
// definition is removed its text becomes "[deleted]", so a text-based locator
// would stop matching exactly when the assertions need it most. Each spec below
// logs in under a name used nowhere else, so `filter({ hasText: NAME })`
// resolves to exactly the cards that spec created.
const loginAs = async (page, email, name) => {
    const response = await page.request.post('/testing/login', {
        form: { email, name, is_admin: '0' },
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

const addDefinition = async (page, text) => {
    await page.locator('textarea[placeholder="What does this word mean?"]').fill(text);
    await page.getByRole('button', { name: 'Submit Definition' }).click();
    await expect(page.getByText(text)).toBeVisible();
};

const definitionCard = (page, authorName) =>
    page.locator('.border.rounded-lg.p-4').filter({ hasText: authorName });

test.describe('Removing a definition', () => {
    test('the author can remove their own definition, and its likes are kept', async ({ page }) => {
        const phrase = `E2E definition to remove ${Date.now()}`;
        await loginAs(page, 'remover-e2e@example.com', 'Removal Author');
        await openFirstWord(page);
        await addDefinition(page, phrase);

        const card = definitionCard(page, 'Removal Author');
        const wordUrl = page.url();
        await expect(card.getByText(/^\d+ likes?$/)).toHaveText('1 like');

        // A single click must not remove anything — confirmation comes first.
        await card.getByRole('button', { name: 'Remove', exact: true }).click();
        await expect(
            page.getByText('Remove this definition? Its likes are kept, but its text stops being shown.')
        ).toBeVisible();
        await expect(card.getByText(phrase)).toBeVisible();

        await page.getByRole('button', { name: 'Remove definition' }).click();

        // The definition stays listed, with its text replaced and its count intact.
        await expect(page.getByText(phrase)).toHaveCount(0);
        await expect(card.getByText('[deleted]')).toBeVisible();
        await expect(card.getByText(/^\d+ likes?$/)).toHaveText('1 like');

        // A removed definition offers no further like or remove action.
        await expect(card.locator('button')).toHaveCount(0);

        // Reloading proves the placeholder comes from the server, not from local
        // component state that a refresh would have cleared.
        await page.goto(wordUrl);
        await expect(page.getByText(phrase)).toHaveCount(0);
        await expect(definitionCard(page, 'Removal Author').getByText('[deleted]')).toBeVisible();
        await expect(definitionCard(page, 'Removal Author').getByText(/^\d+ likes?$/)).toHaveText('1 like');
    });

    test('a non-author sees a like button but no remove control', async ({ page }) => {
        const phrase = `E2E definition someone else wrote ${Date.now()}`;
        await loginAs(page, 'other-author-e2e@example.com', 'Other Author');
        await openFirstWord(page);
        await addDefinition(page, phrase);

        const wordUrl = page.url();

        // Switch to a signed-in third party: removal is author-only, so the check
        // has to be made from a user who is not the definition's author.
        await logout(page);
        await loginAs(page, 'bystander-e2e@example.com', 'Bystander');
        await page.goto(wordUrl);

        const card = definitionCard(page, 'Other Author');
        await expect(card.getByText(phrase)).toBeVisible();
        await expect(card.getByRole('button', { name: 'Remove', exact: true })).toHaveCount(0);
        // Live definitions remain votable and reportable to signed-in users.
        await expect(card.locator('button')).toHaveCount(2);
    });

    test('a guest sees no remove control', async ({ page }) => {
        await openFirstWord(page);

        await expect(page.locator('textarea[placeholder="What does this word mean?"]')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Remove', exact: true })).toHaveCount(0);
    });
});
