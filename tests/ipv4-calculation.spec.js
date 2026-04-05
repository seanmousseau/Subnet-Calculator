// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('IPv4 Subnet Calculation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('page loads with IPv4 tab active by default', async ({ page }) => {
    await expect(page.locator('#panel-ipv4')).toHaveClass(/active/);
    await expect(page.locator('#panel-ipv6')).not.toHaveClass(/active/);
    await expect(page.locator('input#ip')).toBeVisible();
    await expect(page.locator('input#mask')).toBeVisible();
  });

  test('calculates /24 subnet correctly', async ({ page }) => {
    await page.fill('#ip', '192.168.1.0');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv4 .results');
    await expect(results).toBeVisible();

    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Subnet (CIDR)')).toHaveText('192.168.1.0/24');
    await expect(getValue('Netmask (CIDR)')).toHaveText('/24');
    await expect(getValue('Netmask (Octet)')).toHaveText('255.255.255.0');
    await expect(getValue('Wildcard Mask')).toHaveText('0.0.0.255');
    await expect(getValue('First Usable IP')).toHaveText('192.168.1.1');
    await expect(getValue('Last Usable IP')).toHaveText('192.168.1.254');
    await expect(getValue('Broadcast IP')).toHaveText('192.168.1.255');
    await expect(getValue('Usable IPs')).toHaveText('254');
    await expect(getValue('Address Type')).toContainText('Private');
  });

  test('calculates /32 single-host subnet', async ({ page }) => {
    await page.fill('#ip', '10.0.0.1');
    await page.fill('#mask', '32');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv4 .results');
    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Subnet (CIDR)')).toHaveText('10.0.0.1/32');
    await expect(getValue('Usable IPs')).toHaveText('1');
  });

  test('calculates /0 default route', async ({ page }) => {
    await page.fill('#ip', '0.0.0.0');
    await page.fill('#mask', '0');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv4 .results');
    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Netmask (Octet)')).toHaveText('0.0.0.0');
  });

  test('accepts dotted-decimal netmask', async ({ page }) => {
    await page.fill('#ip', '172.16.0.0');
    await page.fill('#mask', '255.255.0.0');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    const results = page.locator('#panel-ipv4 .results');
    const getValue = (label) =>
      results
        .locator('.result-row', { has: page.locator(`.result-label:text("${label}")`) })
        .locator('.result-value');

    await expect(getValue('Netmask (CIDR)')).toHaveText('/16');
    await expect(getValue('Subnet (CIDR)')).toHaveText('172.16.0.0/16');
  });

  test('auto-splits CIDR notation pasted into IP field', async ({ page }) => {
    await page.fill('#ip', '10.0.0.0/8');
    await page.locator('#mask').focus(); // blur triggers auto-split

    await expect(page.locator('#ip')).toHaveValue('10.0.0.0');
    await expect(page.locator('#mask')).toHaveValue('8');
  });

  test('detects address types correctly', async ({ page }) => {
    const cases = [
      { ip: '127.0.0.1', mask: '8', type: 'Loopback' },
      { ip: '169.254.1.1', mask: '16', type: 'Link-local' },
      { ip: '224.0.0.1', mask: '4', type: 'Multicast' },
      { ip: '8.8.8.8', mask: '32', type: 'Public' },
    ];

    for (const { ip, mask, type } of cases) {
      await page.goto('/');
      await page.fill('#ip', ip);
      await page.fill('#mask', mask);
      await page.locator('#panel-ipv4 form button[type="submit"]').click();

      await expect(
        page.locator('#panel-ipv4 .results .badge')
      ).toContainText(type);
    }
  });

  test('shows error for invalid IP address', async ({ page }) => {
    await page.fill('#ip', '999.999.999.999');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv4 .error')).toBeVisible();
    await expect(page.locator('#panel-ipv4 .error')).not.toBeEmpty();
  });

  test('shows error for invalid CIDR prefix', async ({ page }) => {
    await page.fill('#ip', '192.168.1.0');
    await page.fill('#mask', '33');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();

    await expect(page.locator('#panel-ipv4 .error')).toBeVisible();
  });

  test('reset link clears results', async ({ page }) => {
    await page.fill('#ip', '192.168.1.0');
    await page.fill('#mask', '24');
    await page.locator('#panel-ipv4 form button[type="submit"]').click();
    await expect(page.locator('#panel-ipv4 .results')).toBeVisible();

    await page.locator('#panel-ipv4 a.reset').click();
    await expect(page.locator('#panel-ipv4 .results')).not.toBeVisible();
  });
});
