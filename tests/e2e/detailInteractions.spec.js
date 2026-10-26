import { test, expect } from './support/fixtures.js';
import { installBrowserMocks } from './support/browser-mocks.js';

test('definition feedback and keyboard definition shortcut are deterministic', async ({ page, e2e }) => {
    await installBrowserMocks(page);
    const fixture = await e2e.setup();
    await e2e.login(fixture.user.email);
    await page.goto(`/words/${fixture.words[0].slug}`);
    await page.keyboard.press('+');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('button', { name: 'Submit Definition' }).click();
    await expect(page.getByRole('alert')).toHaveText('Please enter a definition.');
    await page.keyboard.press('Escape');
    await page.keyboard.press('p');
    await expect(page.getByTestId('word-audio')).toBeVisible();
});
