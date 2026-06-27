#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (error) {
  console.error('Playwright is not available. Run with NODE_PATH pointing to the bundled node_modules path from Codex workspace dependencies.');
  console.error('Example: NODE_PATH=/Users/ahmaddalao/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules node tests/mobile_ui_screenshots.js');
  process.exit(2);
}

const baseUrl = String(process.env.BASE_URL || '').replace(/\/+$/, '');
const email = String(process.env.INVENTORY_EMAIL || process.env.TEST_EMAIL || '');
const password = String(process.env.INVENTORY_PASSWORD || process.env.TEST_PASSWORD || '');
const outputDir = path.resolve(process.env.OUTPUT_DIR || 'storage/test-screenshots/mobile');

if (!baseUrl || !email || !password) {
  console.error('Usage: BASE_URL=https://inventory.ahmaddalao.com INVENTORY_EMAIL=user@example.com INVENTORY_PASSWORD=secret node tests/mobile_ui_screenshots.js');
  process.exit(2);
}

const pages = [
  ['dashboard', '/dashboard'],
  ['scan-center', '/scan'],
  ['reports', '/reports'],
  ['items', '/items'],
  ['storages', '/storages'],
  ['requests', '/requests'],
  ['handovers', '/handovers'],
  ['purchases', '/purchases'],
  ['reorder', '/reorder'],
  ['files', '/files'],
  ['notifications', '/notifications'],
];

const slug = (value) => String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'page';

const fail = (message) => {
  console.error(`[mobile-ui] FAIL: ${message}`);
  process.exit(1);
};

(async () => {
  fs.mkdirSync(outputDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    userAgent: 'InventoryMobileScreenshot/1.0',
  });
  const page = await context.newPage();

  try {
    await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await Promise.all([
      page.waitForURL(/\/dashboard(?:$|\?)/, { timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);

    for (const [name, route] of pages) {
      const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
      const status = response ? response.status() : 0;

      if (status >= 500) {
        fail(`${route} returned HTTP ${status}`);
      }

      await page.screenshot({
        path: path.join(outputDir, `${slug(name)}.png`),
        fullPage: true,
      });

      console.log(`[mobile-ui] captured ${route} (${status})`);
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  fail(error.message || String(error));
});
