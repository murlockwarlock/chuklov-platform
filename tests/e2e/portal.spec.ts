import { expect, test } from '@playwright/test';

test('client portal shell is responsive', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Chuklov Client Portal' })).toBeVisible();
});
