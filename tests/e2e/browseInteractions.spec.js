import { test, expect } from './support/fixtures.js';
import { installBrowserMocks } from './support/browser-mocks.js';

test('deterministic browse fixtures support filters, keyboard filter toggle, and infinite append', async ({ page, e2e }) => {
    const fixture = await e2e.setup('browse');
    await page.goto('/');
    await expect(page.getByTestId(`word-card-${fixture.words[0].slug}`)).toBeVisible();
    await page.keyboard.press('f');
    await expect(page.getByText('Only words with a free .com')).toBeVisible();
    await page.locator('[data-testid^="word-card-"]').last().scrollIntoViewIfNeeded();
    await expect.poll(() => page.locator('[data-testid^="word-card-"]').count()).toBeGreaterThan(20);
    await page.reload();
    await expect(page.locator('[data-testid^="word-card-"]').first()).toBeVisible();
});

test('detail keyboard shortcuts respect editable and modal guards while audio is mocked', async ({ page, e2e }) => {
    await installBrowserMocks(page);
    const fixture = await e2e.setup();
    await e2e.login(fixture.user.email);
    await page.goto(`/words/${fixture.words[0].slug}`);
    await page.keyboard.press('p');
    await expect(page.getByTestId('word-audio')).toBeVisible();
    await page.keyboard.press('l');
    await expect(page.getByRole('button', { name: 'Remove from favourites' })).toBeVisible();
    await page.keyboard.press('+');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('p');
    await expect(page.getByRole('dialog')).toBeVisible();
});
