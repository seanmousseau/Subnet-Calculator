// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Tab Switching', () => {
  test('switches between IPv4 and IPv6 tabs', async ({ page }) => {
    await page.goto('/');

    // IPv4 tab is active by default
    await expect(page.locator('#panel-ipv4')).toHaveClass(/active/);

    // Click IPv6 tab
    await page.locator('.tab-btn[data-tab="ipv6"]').click();
    await expect(page.locator('#panel-ipv6')).toHaveClass(/active/);
    await expect(page.locator('#panel-ipv4')).not.toHaveClass(/active/);

    // Click IPv4 tab back
    await page.locator('.tab-btn[data-tab="ipv4"]').click();
    await expect(page.locator('#panel-ipv4')).toHaveClass(/active/);
    await expect(page.locator('#panel-ipv6')).not.toHaveClass(/active/);
  });

  test('tab button has active class when selected', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.tab-btn[data-tab="ipv4"]')).toHaveClass(/active/);

    await page.locator('.tab-btn[data-tab="ipv6"]').click();
    await expect(page.locator('.tab-btn[data-tab="ipv6"]')).toHaveClass(/active/);
    await expect(page.locator('.tab-btn[data-tab="ipv4"]')).not.toHaveClass(/active/);
  });
});

test.describe('Theme Toggle', () => {
  test('toggles between dark and light themes', async ({ page }) => {
    await page.goto('/');

    const html = page.locator('html');

    // Click once to toggle theme
    await page.locator('#theme-toggle').click();
    const firstTheme = await html.getAttribute('data-theme');

    // Click again to toggle back
    await page.locator('#theme-toggle').click();
    const secondTheme = await html.getAttribute('data-theme');

    // Themes should differ after each toggle
    expect(firstTheme).not.toBe(secondTheme);
  });

  test('theme persists across page loads', async ({ page }) => {
    await page.goto('/');
    await page.locator('#theme-toggle').click();
    const themeAfterToggle = await page.locator('html').getAttribute('data-theme');

    await page.reload();
    const themeAfterReload = await page.locator('html').getAttribute('data-theme');
    expect(themeAfterReload).toBe(themeAfterToggle);
  });
});

test.describe('Shareable URLs', () => {
  test('shows share bar after IPv4 calculation', async ({ page }) => {
    await page.goto('/');
    await page.fill('#ip', '192.168.1.0');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv4 .share-bar')).toBeVisible();
    const shareUrl = await page.locator('#panel-ipv4 .share-url').textContent();
    expect(shareUrl).toContain('tab=ipv4');
    expect(shareUrl).toContain('ip=192.168.1.0');
    expect(shareUrl).toContain('mask=24');
  });

  test('GET parameters auto-calculate results', async ({ page }) => {
    await page.goto('/?tab=ipv4&ip=10.0.0.0&mask=8');

    await expect(page.locator('#panel-ipv4 .results')).toBeVisible();

    const getValue = (label) =>
      page.locator('#panel-ipv4 .results')
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Subnet (CIDR)')).toHaveText('10.0.0.0/8');
    await expect(getValue('Address Type')).toContainText('Private');
  });

  test('GET parameters auto-calculate IPv6 results', async ({ page }) => {
    await page.goto('/?tab=ipv6&ipv6=2001:db8::&prefix=32');

    await expect(page.locator('#panel-ipv6 .results')).toBeVisible();

    const getValue = (label) =>
      page.locator('#panel-ipv6 .results')
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Network (CIDR)')).toHaveText('2001:db8::/32');
  });
});

test.describe('Page Structure', () => {
  test('has correct page title', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveTitle(/Subnet Calculator/);
  });

  test('has meta description', async ({ page }) => {
    await page.goto('/');
    const desc = page.locator('meta[name="description"]');
    await expect(desc).toHaveAttribute('content', /.+/);
  });

  test('form inputs have proper placeholders', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#ip')).toHaveAttribute('placeholder', /192\.168\.1\.0/);
    await expect(page.locator('#mask')).toHaveAttribute('placeholder', /\/24/);
  });
});
