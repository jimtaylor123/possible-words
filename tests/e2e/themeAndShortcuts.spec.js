import { test, expect } from '@playwright/test';

test('purple Word Lab defaults to dark and remembers light mode across navigation', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'dark');
    await expect(page.locator('.hero-cta')).toHaveCSS('background-color', 'rgb(197, 180, 255)');
    await expect(page.getByLabel('Design preview controls')).toHaveCount(0);
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    await page.getByRole('link', { name: 'About', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'light');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'light');
});

test('search shortcut focuses without inserting a character and pauses in the help dialog', async ({ page }) => {
    await page.goto('/');
    const search = page.getByRole('combobox', { name: 'Search words' });
    await expect(search).toBeVisible();
    await page.keyboard.press('s');
    await expect(search).toBeFocused();
    await expect(search).toHaveValue('');
    await search.fill('s');
    await page.keyboard.type('s');
    await expect(search).toHaveValue('ss');
    await page.locator('.discovery-heading').getByRole('button', { name: 'shortcuts', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect((await page.getByRole('dialog').boundingBox()).width).toBeLessThanOrEqual(360);
    await expect(page.getByRole('dialog').locator('kbd')).toHaveText(['s', 'f', 'p', 'l', '+', 'Esc']);
    await page.keyboard.press('s');
    await expect(search).not.toBeFocused();
    await page.keyboard.press('f');
    await expect(page.getByRole('button', { name: 'Show filters', exact: true })).toBeAttached();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'shortcuts', exact: true })).toBeFocused();
});

test('word details expose shortcuts in the footer', async ({ page }) => {
    await page.goto('/');
    await page.locator('.word-discovery-card').first().click();
    await expect(page.locator('nav').getByRole('button', { name: 'shortcuts', exact: true })).toHaveCount(0);
    await page.locator('footer').getByRole('button', { name: 'shortcuts', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('dialog').getByText('Word details', { exact: true })).toBeVisible();
});

test('Word Lab fits a 320px screen in both modes', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto('/');
    await expect(page.locator('.word-discovery-card').first()).toBeAttached();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'shortcuts', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect((await page.getByRole('dialog').boundingBox()).width).toBeLessThanOrEqual(288);
});
