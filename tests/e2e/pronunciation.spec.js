import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

test.describe('Pronunciation feature', () => {
    test.beforeAll(() => {
        execSync('php artisan words:generate-pronunciation --force 2>/dev/null', { cwd: '.' });
    });

    test('word show page displays IPA pronunciation', async ({ page }) => {
        await page.goto('/words');

        const wordLink = page.locator('a[href*="/words/"]').first();
        await wordLink.waitFor({ state: 'visible' });

        const wordText = await wordLink.locator('span.text-xl').first().textContent();
        await wordLink.click();

        await expect(page.locator('h1')).toHaveText(wordText.trim());

        const ipa = page.locator('.font-mono').first();
        await expect(ipa).toBeVisible();
    });

    test('audio play button does not appear when no audio_url', async ({ page }) => {
        await page.goto('/words');

        const wordLink = page.locator('a[href*="/words/"]').first();
        await wordLink.waitFor({ state: 'visible' });
        await wordLink.click();

        const playButton = page.getByText('Play');
        await expect(playButton).not.toBeVisible();
    });
});
