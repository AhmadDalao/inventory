#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (error) {
  console.error('Playwright is not available. Set NODE_PATH to the bundled workspace node_modules directory.');
  process.exit(2);
}

const baseUrl = String(process.env.BASE_URL || '').replace(/\/+$/, '');
const email = String(process.env.INVENTORY_EMAIL || process.env.TEST_EMAIL || '');
const password = String(process.env.INVENTORY_PASSWORD || process.env.TEST_PASSWORD || '');
const outputDir = path.resolve(process.env.OUTPUT_DIR || 'storage/test-screenshots/responsive');
const captureScreenshots = process.env.CAPTURE_SCREENSHOTS === '1';

if (!baseUrl || !email || !password) {
  console.error('Usage: BASE_URL=https://inventory.ahmaddalao.com INVENTORY_EMAIL=user@example.com INVENTORY_PASSWORD=secret node tests/responsive_ui_smoke.js');
  process.exit(2);
}

const viewportMatrix = [
  { name: 'compact-phone', width: 390, height: 844, mobile: true },
  { name: 'large-phone', width: 430, height: 932, mobile: true },
  { name: 'tablet-portrait', width: 768, height: 1024, mobile: false },
  { name: 'tablet-landscape', width: 1024, height: 768, mobile: false },
  { name: 'desktop', width: 1440, height: 1000, mobile: false },
  { name: 'wide-desktop', width: 1920, height: 1080, mobile: false },
];

const routes = [
  ['dashboard', '/dashboard'],
  ['items', '/items'],
  ['storages', '/storages'],
  ['movements', '/movements'],
  ['scan-center', '/scan'],
  ['manual-stock', '/scan/manual'],
  ['requests', '/requests'],
  ['handovers', '/handovers'],
  ['purchases', '/purchases'],
  ['assets', '/company-assets'],
  ['asset-create', '/company-assets/create'],
  ['stocktakes', '/stocktakes'],
  ['reorder', '/reorder'],
  ['reports', '/reports'],
  ['saved-reports', '/reports/presets'],
  ['labels', '/labels'],
  ['files', '/files'],
  ['suppliers', '/suppliers'],
  ['notifications', '/notifications'],
  ['users', '/users'],
  ['team-hierarchy', '/users/hierarchy'],
  ['settings', '/settings/site'],
  ['documentation', '/documentation'],
];

const slug = (value) => String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'page';
const failures = [];

const addFailure = (viewport, route, message) => {
  failures.push(`[${viewport}] ${route}: ${message}`);
};

const login = async (page) => {
  const response = await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' });
  if (!response || response.status() >= 400) {
    throw new Error(`Login page returned HTTP ${response ? response.status() : 'no response'}`);
  }

  const passwordInput = page.locator('input[name="password"]');
  if (!(await passwordInput.isVisible())) {
    throw new Error('Password input is not visible');
  }

  await page.fill('input[name="email"]', email);
  await passwordInput.fill(password);
  await Promise.all([
    page.waitForURL(/\/dashboard(?:$|\?)/, { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
};

const inspectLayout = async (page) => page.evaluate(() => {
  const root = document.documentElement;
  const body = document.body;
  const viewportWidth = window.innerWidth;
  const globalOverflow = Math.max(root.scrollWidth, body ? body.scrollWidth : 0) - viewportWidth;
  const intentionalScrollSelector = '.table-wrap, .data-table-scroll, .table-scroll, .summary-table-scroll, [data-horizontal-scroll]';
  const interactiveSelector = 'button, a, input, select, textarea, summary, [role="button"]';
  const clipped = [];

  document.querySelectorAll(interactiveSelector).forEach((element) => {
    if (!(element instanceof HTMLElement) || element.hidden) {
      return;
    }

    const sidebar = element.closest('[data-sidebar]');
    const shell = element.closest('[data-shell]');
    if (sidebar && shell && !shell.classList.contains('nav-open')) {
      return;
    }

    const style = window.getComputedStyle(element);
    if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
      return;
    }

    if (element.closest(intentionalScrollSelector)) {
      return;
    }

    const rect = element.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1 || rect.bottom < 0 || rect.top > window.innerHeight * 2) {
      return;
    }

    if (rect.left < -2 || rect.right > viewportWidth + 2) {
      clipped.push({
        tag: element.tagName.toLowerCase(),
        text: (element.textContent || element.getAttribute('aria-label') || '').trim().slice(0, 80),
        left: Math.round(rect.left),
        right: Math.round(rect.right),
      });
    }
  });

  const scrollRegions = Array.from(document.querySelectorAll(intentionalScrollSelector))
    .filter((element) => element instanceof HTMLElement && element.scrollWidth > element.clientWidth + 2)
    .length;

  return {
    globalOverflow: Math.round(globalOverflow),
    clipped: clipped.slice(0, 8),
    scrollRegions,
  };
});

const inspectDashboardWorkflowCards = async (page) => page.evaluate(() => {
  const grid = document.querySelector('.workflow-panel-grid');
  const rows = Array.from(document.querySelectorAll('.workflow-mini-card'));
  const issues = [];

  rows.forEach((row, index) => {
    if (!(row instanceof HTMLElement)) {
      return;
    }

    const copy = row.querySelector('.workflow-mini-card-copy');
    const meta = row.querySelector('.workflow-mini-card-meta');
    if (!(copy instanceof HTMLElement) || !(meta instanceof HTMLElement)) {
      issues.push(`row ${index + 1} is missing its copy or metadata region`);
      return;
    }

    if (row.scrollWidth > row.clientWidth + 2) {
      issues.push(`row ${index + 1} overflows horizontally`);
    }

    const metaStyle = window.getComputedStyle(meta);
    if (meta.getBoundingClientRect().width < 88) {
      issues.push(`row ${index + 1} metadata collapsed below 88px`);
    }

    meta.querySelectorAll('.pill, .tiny-copy').forEach((element) => {
      const style = window.getComputedStyle(element);
      if (style.overflowWrap === 'anywhere') {
        issues.push(`row ${index + 1} metadata can wrap at every character`);
      }
    });

    if (metaStyle.textAlign !== 'right' && window.innerWidth > 560) {
      issues.push(`row ${index + 1} desktop metadata alignment changed`);
    }
  });

  const columnCount = grid instanceof HTMLElement
    ? window.getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length
    : 0;

  if (window.innerWidth > 1100 && columnCount > 2) {
    issues.push(`workflow queue uses ${columnCount} columns instead of at most 2`);
  }

  if (window.innerWidth <= 1024 && columnCount > 1) {
    issues.push(`workflow queue uses ${columnCount} columns on a phone or tablet`);
  }

  return { rows: rows.length, columnCount, issues };
});

const inspectSidebar = async (page) => {
  await page.click('.main-panel [data-menu-toggle]');
  await page.waitForTimeout(220);

  const result = await page.evaluate(() => {
    const shell = document.querySelector('[data-shell]');
    const sidebar = document.querySelector('[data-sidebar]');
    const navigation = sidebar ? sidebar.querySelector('.nav-links') : null;

    if (!(shell instanceof HTMLElement) || !(sidebar instanceof HTMLElement) || !(navigation instanceof HTMLElement)) {
      return { error: 'sidebar shell is incomplete' };
    }

    const rect = sidebar.getBoundingClientRect();
    const navigationStyle = window.getComputedStyle(navigation);
    const requiresScroll = navigation.scrollHeight > navigation.clientHeight + 2;

    return {
      open: shell.classList.contains('nav-open'),
      left: Math.round(rect.left),
      right: Math.round(rect.right),
      height: Math.round(rect.height),
      requiresScroll,
      scrollable: !requiresScroll || ['auto', 'scroll'].includes(navigationStyle.overflowY),
    };
  });

  await page.evaluate(() => {
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (backdrop instanceof HTMLElement) {
      backdrop.click();
    }
  });
  await page.waitForTimeout(200);
  return result;
};

(async () => {
  if (captureScreenshots) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  const browser = await chromium.launch({ headless: true });

  try {
    for (const viewport of viewportMatrix) {
      const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        deviceScaleFactor: viewport.mobile ? 2 : 1,
        isMobile: viewport.mobile,
        hasTouch: viewport.width <= 1024,
        userAgent: `InventoryResponsiveSmoke/1.0 (${viewport.name})`,
      });
      const page = await context.newPage();
      const consoleErrors = [];
      const failedResponses = [];

      page.on('console', (message) => {
        if (message.type() === 'error') {
          consoleErrors.push(message.text());
        }
      });
      page.on('pageerror', (error) => consoleErrors.push(error.message || String(error)));
      page.on('response', (response) => {
        try {
          const responseUrl = new URL(response.url());
          const targetUrl = new URL(baseUrl);
          if (responseUrl.origin === targetUrl.origin && response.status() >= 400) {
            failedResponses.push(`${response.status()} ${responseUrl.pathname}`);
          }
        } catch (error) {
          // Ignore non-URL browser internals.
        }
      });

      try {
        await login(page);
      } catch (error) {
        addFailure(viewport.name, '/login', error.message || String(error));
        await context.close();
        continue;
      }

      if (viewport.width <= 1360) {
        const sidebar = await inspectSidebar(page);
        if (sidebar.error) {
          addFailure(viewport.name, '/dashboard', sidebar.error);
        } else {
          if (!sidebar.open || sidebar.left < -2 || sidebar.right > viewport.width + 2 || sidebar.height > viewport.height + 2) {
            addFailure(viewport.name, '/dashboard', `sidebar drawer bounds are invalid: ${JSON.stringify(sidebar)}`);
          }
          if (!sidebar.scrollable) {
            addFailure(viewport.name, '/dashboard', 'sidebar navigation does not scroll when its links exceed the viewport');
          }
        }
      }

      for (const [name, route] of routes) {
        consoleErrors.length = 0;
        failedResponses.length = 0;

        const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
        const status = response ? response.status() : 0;

        if (!response || status >= 400) {
          addFailure(viewport.name, route, `HTTP ${status || 'no response'}`);
          continue;
        }

        await page.waitForTimeout(120);
        const layout = await inspectLayout(page);

        if (layout.globalOverflow > 2) {
          addFailure(viewport.name, route, `page-level horizontal overflow is ${layout.globalOverflow}px`);
        }
        if (layout.clipped.length) {
          addFailure(viewport.name, route, `clipped controls: ${JSON.stringify(layout.clipped)}`);
        }
        if (consoleErrors.length) {
          addFailure(viewport.name, route, `console errors: ${consoleErrors.join(' | ')}`);
        }
        if (failedResponses.length) {
          addFailure(viewport.name, route, `failed same-origin responses: ${failedResponses.join(' | ')}`);
        }

        if (route === '/dashboard') {
          const workflowCards = await inspectDashboardWorkflowCards(page);
          if (workflowCards.issues.length) {
            addFailure(viewport.name, route, `workflow card layout: ${workflowCards.issues.join(' | ')}`);
          }
        }

        if (captureScreenshots) {
          const viewportDir = path.join(outputDir, viewport.name);
          fs.mkdirSync(viewportDir, { recursive: true });
          await page.screenshot({
            path: path.join(viewportDir, `${slug(name)}.png`),
            fullPage: true,
          });
        }

        console.log(`[responsive-ui] ${viewport.name} ${route} (${status}) overflow=${layout.globalOverflow}px tables=${layout.scrollRegions}`);
      }

      await context.close();
    }
  } finally {
    await browser.close();
  }

  if (failures.length) {
    console.error('\n[responsive-ui] FAIL');
    failures.forEach((failure) => console.error(`- ${failure}`));
    process.exit(1);
  }

  console.log(`\n[responsive-ui] PASS: ${routes.length} routes across ${viewportMatrix.length} viewports.`);
})().catch((error) => {
  console.error(`[responsive-ui] FAIL: ${error.message || String(error)}`);
  process.exit(1);
});
