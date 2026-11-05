import { test, expect } from '@playwright/test';

test('design choice and light mode survive navigation and reload', async ({ page }) => {
    await page.goto('/?design=folio');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Every word wasmade up once.');
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    await page.getByRole('link', { name: 'About', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-design', 'folio');
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'light');
    await page.reload();
    await expect(page.getByRole('button', { name: '02 The Unwritten' })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: '03 Word Playground' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-design', 'play');
});

for (const design of ['lab', 'folio', 'play']) {
    test(`${design} fits a 320px screen in both modes`, async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 900 });
        await page.goto(`/?design=${design}`);
        await expect(page.locator('.word-discovery-card').first()).toBeAttached();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.getByRole('button', { name: 'Switch to light mode' }).click();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });
}
