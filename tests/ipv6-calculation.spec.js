// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('IPv6 Subnet Calculation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/?tab=ipv6');
  });

  test('IPv6 tab is active when navigated via query param', async ({ page }) => {
    await expect(page.locator('#panel-ipv6')).toHaveClass(/active/);
    await expect(page.locator('#panel-ipv4')).not.toHaveClass(/active/);
  });

  test('calculates /64 subnet correctly', async ({ page }) => {
    await page.fill('#ipv6', '2001:db8::1');
    await page.fill('#prefix', '64');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv6 .results');
    await expect(results).toBeVisible();

    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Network (CIDR)')).toHaveText('2001:db8::/64');
    await expect(getValue('Prefix Length')).toHaveText('/64');
    await expect(getValue('First IP')).toHaveText('2001:db8::');
    await expect(getValue('Last IP')).toHaveText('2001:db8::ffff:ffff:ffff:ffff');
    await expect(getValue('Total Addresses')).toHaveText('2^64');
    await expect(getValue('Address Type')).toContainText('Documentation');
  });

  test('calculates /128 single-host', async ({ page }) => {
    await page.fill('#ipv6', '::1');
    await page.fill('#prefix', '128');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv6 .results');
    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Network (CIDR)')).toHaveText('::1/128');
    await expect(getValue('Total Addresses')).toHaveText('1');
    await expect(getValue('Address Type')).toContainText('Loopback');
  });

  test('calculates /48 subnet', async ({ page }) => {
    await page.fill('#ipv6', 'fd00:abcd:1234::');
    await page.fill('#prefix', '48');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv6 .results');
    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Prefix Length')).toHaveText('/48');
    await expect(getValue('Total Addresses')).toHaveText('2^80');
    await expect(getValue('Address Type')).toContainText('Unique Local');
  });

  test('auto-splits CIDR notation in IPv6 field', async ({ page }) => {
    await page.fill('#ipv6', '2001:db8::/32');
    await page.locator('#prefix').focus();

    await expect(page.locator('#ipv6')).toHaveValue('2001:db8::');
    await expect(page.locator('#prefix')).toHaveValue('32');
  });

  test('shows error for invalid IPv6 address', async ({ page }) => {
    await page.fill('#ipv6', 'not-an-ipv6');
    await page.fill('#prefix', '64');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv6 .error')).toBeVisible();
    await expect(page.locator('#panel-ipv6 .error')).not.toBeEmpty();
  });

  test('shows error for prefix out of range', async ({ page }) => {
    await page.fill('#ipv6', '2001:db8::');
    await page.fill('#prefix', '129');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv6 .error')).toBeVisible();
  });

  test('reset clears IPv6 results', async ({ page }) => {
    await page.fill('#ipv6', '2001:db8::');
    await page.fill('#prefix', '64');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();
    await expect(page.locator('#panel-ipv6 .results')).toBeVisible();

    await page.locator('#panel-ipv6 a.reset').click();
    await expect(page.locator('#panel-ipv6 .results')).not.toBeVisible();
  });
});
