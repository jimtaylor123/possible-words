import { test, expect } from '@playwright/test';

test.describe('Pronunciation feature', () => {
    test('word show page displays IPA pronunciation', async ({ page }) => {
        await page.goto('/words');

        const wordLink = page.locator('[role="link"]').first();
        await wordLink.waitFor({ state: 'visible' });
        await wordLink.click();

        await expect(page.locator('.font-mono').first()).toBeVisible();
    });

    test('audio play button does not appear when no audio_url', async ({ page }) => {
        await page.goto('/words');

        const wordLink = page.locator('[role="link"]').first();
        await wordLink.waitFor({ state: 'visible' });
        await wordLink.click();

        await expect(page.getByText('Play')).not.toBeVisible();
    });
});
