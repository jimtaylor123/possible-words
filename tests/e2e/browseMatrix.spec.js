import { test, expect } from './support/fixtures.js';
import { installBrowserMocks } from './support/browser-mocks.js';

test('fixture browse query combinations, sorting, restoration, and clear reset render deterministically', async ({ page, e2e }) => {
    const fixture = await e2e.setup('browse');
    for (const [sort, direction] of [['created_at', 'desc'], ['created_at', 'asc'], ['alphabetical', 'asc'], ['alphabetical', 'desc'], ['letters', 'asc'], ['letters', 'desc'], ['popularity', 'asc'], ['popularity', 'desc']]) {
        await page.goto(`/?syllables=2&length=5&starts_with=E&definitions=without&domain_available=1&sort=${sort}&direction=${direction}`);
        await expect(page.getByRole('button', { name: 'Hide filters' })).toBeVisible();
        expect(Object.fromEntries(new URL(page.url()).searchParams)).toEqual(expect.objectContaining({ sort, direction, domain_available: '1' }));
    }
    await page.goto('/?starts_with=E&sort=alphabetical&direction=asc');
    await page.reload();
    await expect(page.getByRole('button', { name: 'Hide filters' })).toBeVisible();
    await page.getByRole('button', { name: 'Hide filters' }).click();
    await expect(page).toHaveURL(/sort=created_at.*direction=desc|direction=desc.*sort=created_at/);
    await expect(page.getByTestId(`word-card-${fixture.words[0].slug}`)).toBeVisible();
});

test('suggestions have keyboard and accessible no-match behavior without stale responses', async ({ page, e2e }) => {
    const fixture = await e2e.setup('browse');
    await page.route('**/suggestions*', async route => {
        const url = new URL(route.request().url());
        const q = url.searchParams.get('q');
        if (q === 'zz') await new Promise(resolve => setTimeout(resolve, 150));
        await route.continue();
    });
    await page.goto('/');
    const input = page.locator('input[placeholder="Search words..."]');
    await input.fill('zz');
    await input.fill(fixture.words[0].text.slice(0, 4));
    await expect(input).toHaveAttribute('role', 'combobox');
    await expect(input).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('.n-auto-complete-menu .n-base-select-option').first()).toBeVisible();
    await input.press('ArrowDown');
    await input.press('Enter');
    await expect(page).toHaveURL(/\/words\//);
    await page.goto('/');
    await input.fill('zz');
    await expect(page.getByText('No matching words').last()).toBeVisible();
});

test('domain detail states and card audio replay/error events are deterministic', async ({ page, e2e }) => {
    await installBrowserMocks(page);
    const fixture = await e2e.setup('browse');
    await page.goto(`/words/${fixture.words[0].slug}`);
    await expect(page.getByText(/is available/)).toBeVisible();
    await page.goto(`/words/${fixture.words[1].slug}`);
    await expect(page.getByText(/is taken/)).toBeVisible();
    await page.goto(`/words/${fixture.words[2].slug}`);
    await expect(page.getByText(/visit .*\.com/)).toBeVisible();
    await page.goto(`/words/${fixture.words[3].slug}`);
    await expect(page.getByText(/visit .*\.com/)).toBeVisible();
    await page.goto('/');
    const audio = page.getByTestId(`card-audio-${fixture.words[0].slug}`);
    await audio.click();
    await audio.click();
    await page.locator('audio').dispatchEvent('ended');
    await audio.click();
    await page.locator('audio').dispatchEvent('error');
    await expect(audio).toBeVisible();
});
