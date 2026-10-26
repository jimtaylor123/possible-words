import { test, expect } from './support/fixtures.js';
import { installBrowserMocks } from './support/browser-mocks.js';

test('favourites have deterministic empty, browse-toggle, detail-toggle, and popular states', async ({ page, e2e }) => {
    await installBrowserMocks(page);
    const fixture = await e2e.setup('browse');
    await e2e.login(fixture.user.email);
    await page.goto('/favourites');
    await expect(page.getByText("You haven't favourited any words yet.")).toBeVisible();
    await page.goto('/');
    const card = page.getByTestId(`word-card-${fixture.words[0].slug}`);
    const favouriteRequest = page.waitForResponse(response => new URL(response.url()).pathname.endsWith(`/words/${fixture.words[0].slug}/favourite`) && response.request().method() === 'POST');
    await card.getByRole('button', { name: `Add ${fixture.words[0].text} to favourites` }).click();
    await favouriteRequest;
    await expect(card.getByRole('button', { name: /Remove .* from favourites/ })).toBeVisible();
    await card.click();
    await page.getByRole('button', { name: 'Remove from favourites' }).click();
    await page.getByRole('button', { name: 'Add to favourites' }).click();
    await page.goto('/favourites');
    await expect(page.getByText(fixture.words[0].text)).toBeVisible();
    await page.getByRole('button', { name: `Play pronunciation for ${fixture.words[0].text}` }).click();
    await page.goto('/popular');
    await expect(page.getByRole('heading', { name: 'Most Favourited Words' })).toBeVisible();
});
