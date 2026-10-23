import { test, expect } from '@playwright/test';

const viewports = [320, 375, 768];

const login = async (page) => {
    const response = await page.request.post('/testing/login', {
        form: { email: `responsive-${Date.now()}@example.com`, name: 'Responsive User', is_admin: '0' },
    });
    expect(response.ok()).toBeTruthy();
};

const expectInBounds = async (locator, width) => {
    await expect(locator).toBeVisible();
    const box = await locator.boundingBox();
    expect(box).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);
};

const expectNoHorizontalOverflow = async (page) => {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => window.innerWidth));
};

const expectCompleteMetadata = async (page, width) => {
    const metadata = page.getByText('A fully populated responsive metadata fixture.').locator('..').getByTestId('definition-metadata');
    const items = metadata.locator(':scope > span');

    await expect(metadata).toBeVisible();
    await expect(items).toHaveCount(4);
    await expect(items.nth(0)).toContainText('Metadata Fixture Author with a deliberately long display name');
    await expect(items.nth(1)).toHaveText('47 votes');
    await expect(items.nth(2)).toHaveText('noun');
    await expect(items.nth(3)).toHaveText('AI-generated');

    for (let index = 0; index < await items.count(); index++) {
        const item = items.nth(index);
        await expect(item).not.toHaveText(/^[•·|]$/);
        await expectInBounds(item, width);
    }
};

test.describe('responsive primary navigation', () => {
    for (const width of viewports) {
        test(`keeps guest controls reachable at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/');

            await expectInBounds(page.getByRole('link', { name: 'Possible Words' }), width);
            await expectInBounds(page.getByRole('link', { name: 'About' }), width);
            await expectInBounds(page.getByRole('link', { name: 'Popular' }), width);
            await expectInBounds(page.getByRole('button', { name: 'Continue with Google' }), width);
            await expect(page.locator('nav [aria-current="page"]')).toHaveCount(1);
            await expect(page.getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page');
            await expectNoHorizontalOverflow(page);
        });

        test(`keeps signed-in controls reachable at ${width}px`, async ({ page }) => {
            await login(page);
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/');

            await expectInBounds(page.getByRole('link', { name: 'Favourites' }), width);
            await expectInBounds(page.getByRole('button', { name: 'Notifications' }), width);
            await expectInBounds(page.getByRole('button', { name: 'Account menu for Responsive User' }), width);
            await expect(page.locator('nav [aria-current="page"]')).toHaveCount(1);
            await expect(page.getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page');
            await page.getByRole('button', { name: 'Notifications' }).click();
            const notifications = page.getByRole('region', { name: 'Notifications' });
            await expectInBounds(notifications, width);
            await page.getByRole('button', { name: 'Account menu for Responsive User' }).click();
            await expect(page.getByText('Account', { exact: true })).toBeVisible();
            await expectNoHorizontalOverflow(page);
        });
    }
});

test.describe('responsive definition metadata', () => {
    for (const width of viewports) {
        test(`keeps complete Home metadata together at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/');

            await expectCompleteMetadata(page, width);
            await expectNoHorizontalOverflow(page);
        });

        test(`keeps complete Popular metadata together at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/popular');

            await expectCompleteMetadata(page, width);
            await expectNoHorizontalOverflow(page);
        });
    }
});
