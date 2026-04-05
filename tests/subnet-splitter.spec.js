// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('IPv4 Subnet Splitter', () => {
  test('splits /24 into /25 subnets', async ({ page }) => {
    await page.goto('/');
    await page.fill('#ip', '192.168.1.0');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv4 .results')).toBeVisible();

    await page.fill('#panel-ipv4 .splitter-input', '/25');
    await page.locator('#panel-ipv4 .splitter-btn').click();

    const splitItems = page.locator('#panel-ipv4 .split-item');
    await expect(splitItems).toHaveCount(2);
    await expect(splitItems.nth(0)).toContainText('192.168.1.0/25');
    await expect(splitItems.nth(1)).toContainText('192.168.1.128/25');
  });

  test('splits /24 into /26 subnets', async ({ page }) => {
    await page.goto('/');
    await page.fill('#ip', '10.0.0.0');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    await page.fill('#panel-ipv4 .splitter-input', '/26');
    await page.locator('#panel-ipv4 .splitter-btn').click();

    const splitItems = page.locator('#panel-ipv4 .split-item');
    await expect(splitItems).toHaveCount(4);
  });

  test('shows error for invalid split prefix', async ({ page }) => {
    await page.goto('/');
    await page.fill('#ip', '192.168.1.0');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    // Try to split into a prefix smaller than the original
    await page.fill('#panel-ipv4 .splitter-input', '/20');
    await page.locator('#panel-ipv4 .splitter-btn').click();

    await expect(page.locator('#panel-ipv4 .splitter .error')).toBeVisible();
  });
});

test.describe('IPv6 Subnet Splitter', () => {
  test('splits /48 into /64 subnets', async ({ page }) => {
    await page.goto('/?tab=ipv6');
    await page.fill('#ipv6', '2001:db8:abcd::');
    await page.fill('#prefix', '48');
    await page.locator('#panel-ipv6 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv6 .results')).toBeVisible();

    await page.fill('#panel-ipv6 .splitter-input', '/64');
    await page.locator('#panel-ipv6 .splitter-btn').click();

    const splitItems = page.locator('#panel-ipv6 .split-item');
    // With default max of 16, we should see 16 items and a "+ more" indicator
    await expect(splitItems.first()).toBeVisible();
    await expect(page.locator('#panel-ipv6 .split-more')).toBeVisible();
  });
});
