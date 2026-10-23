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

test.describe('responsive primary navigation', () => {
    for (const width of viewports) {
        test(`keeps guest controls reachable at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/');

            await expectInBounds(page.getByRole('link', { name: 'Possible Words' }), width);
            await expectInBounds(page.getByRole('link', { name: 'About' }), width);
            await expectInBounds(page.getByRole('link', { name: 'Popular' }), width);
            await expectInBounds(page.getByRole('button', { name: 'Continue with Google' }), width);
            await expect(page.getByRole('link', { name: 'Possible Words' })).toHaveAttribute('aria-current', 'page');
            await expectNoHorizontalOverflow(page);
        });

        test(`keeps signed-in controls reachable at ${width}px`, async ({ page }) => {
            await login(page);
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/');

            await expectInBounds(page.getByRole('link', { name: 'Favourites' }), width);
            await expectInBounds(page.getByRole('button', { name: 'Notifications' }), width);
            await expectInBounds(page.getByRole('button', { name: 'Account menu for Responsive User' }), width);
            await expect(page.getByRole('link', { name: 'Possible Words' })).toHaveAttribute('aria-current', 'page');
            await page.getByRole('button', { name: 'Notifications' }).click();
            await expect(page.getByRole('region', { name: 'Notifications' })).toBeVisible();
            await page.getByRole('button', { name: 'Account menu for Responsive User' }).click();
            await expect(page.getByText('Account', { exact: true })).toBeVisible();
            await expectNoHorizontalOverflow(page);
        });
    }
});
