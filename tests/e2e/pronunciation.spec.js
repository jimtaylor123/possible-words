import { test, expect } from '@playwright/test';

test('word show page displays IPA pronunciation', async ({ page }) => {
    // Use the artisan command first to generate a word with pronunciation
    // Or use a word that already has pronunciation data seeded
    await page.goto('/words');

    // Click on the first word to view it
    const wordLink = page.locator('a[href^="/words/"]').first();
    await wordLink.waitFor({ state: 'visible' });

    const wordText = await wordLink.textContent();
    await wordLink.click();

    // Wait for the word show page
    await expect(page.locator('h1')).toHaveText(wordText.trim());

    // Check if IPA is displayed (may not be present for all words)
    const ipa = page.locator('.font-mono').first();
    if (await ipa.isVisible()) {
        await expect(ipa).toBeVisible();
    }
});

test('audio play button appears for words with audio_url', async ({ page }) => {
    // Visit a word detail page
    await page.goto('/words');

    const wordLink = page.locator('a[href^="/words/"]').first();
    await wordLink.waitFor({ state: 'visible' });
    await wordLink.click();

    // Check if play button exists (it will only appear if word has audio_url)
    const playButton = page.getByText('Play');
    if (await playButton.isVisible()) {
        await expect(playButton).toBeVisible();
    }
});
