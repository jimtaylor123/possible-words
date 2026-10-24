import { test, expect } from '@playwright/test';

test.describe('Pronunciation feature', () => {
    test('word show page displays IPA pronunciation', async ({ page }) => {
        await page.goto('/words/quohit');

        await expect(page.locator('.font-mono').first()).toBeVisible();
    });

    test('audio play button does not appear when no audio_url', async ({ page }) => {
        await page.goto('/words/metadataview');

        await expect(page.getByText('Play', { exact: true })).not.toBeVisible();
    });
});
