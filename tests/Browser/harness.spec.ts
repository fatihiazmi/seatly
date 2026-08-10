import { expect, test } from '@playwright/test';

/**
 * F1-03 — smoke test for the browser layer.
 *
 * Proves Playwright drives a real browser against a served application and that
 * Inertia mounts. First demonstrated red; see docs/evidence/F1-03/red.md.
 */
test('the browser layer reaches the application', async ({ page }) => {
    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
});

test('inertia mounts the vue application', async ({ page }) => {
    await page.goto('/');

    // Inertia hands the serialised page object to Vue, which consumes the
    // data-page attribute on mount and stamps data-v-app in its place.
    await expect(page.locator('#app[data-v-app]')).toHaveCount(1);
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
});
