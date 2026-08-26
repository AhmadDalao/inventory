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
const outputDir = path.resolve(process.env.OUTPUT_DIR || 'storage/test-screenshots/responsive');
const screenshotMode = String(process.env.SCREENSHOTS || '1') !== '0';

if (!baseUrl || !email || !password) {
  console.error('Usage: BASE_URL=https://inventory.ahmaddalao.com INVENTORY_EMAIL=user@example.com INVENTORY_PASSWORD=secret node tests/mobile_ui_screenshots.js');
  process.exit(2);
}

const defaultPages = [
  ['dashboard', '/dashboard'],
  ['scan-center', '/scan'],
  ['reports', '/reports'],
  ['items', '/items'],
  ['storages', '/storages'],
  ['movement-log', '/movements'],
  ['requests', '/requests'],
  ['handovers', '/handovers'],
  ['purchases', '/purchases'],
  ['assets', '/company-assets'],
  ['stocktakes', '/stocktakes'],
  ['suppliers', '/suppliers'],
  ['reorder', '/reorder'],
  ['labels', '/labels'],
  ['files', '/files'],
  ['documentation', '/documentation'],
  ['notifications', '/notifications'],
  ['admins', '/users'],
  ['audit-log', '/audit-log'],
  ['email-logs', '/email-logs'],
  ['website-control', '/settings/site'],
  ['mobile-access', '/mobile-access'],
];

const defaultViewports = [
  { name: 'phone-390', width: 390, height: 844, isMobile: true },
  { name: 'phone-430', width: 430, height: 932, isMobile: true },
  { name: 'tablet-768', width: 768, height: 1024, isMobile: true },
  { name: 'tablet-1024', width: 1024, height: 768, isMobile: false },
  { name: 'desktop-1440', width: 1440, height: 1000, isMobile: false },
  { name: 'wide-1920', width: 1920, height: 1080, isMobile: false },
];

const slug = (value) => String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'page';

const parseViewports = () => {
  const configured = String(process.env.VIEWPORTS || '').trim();
  if (!configured) {
    return defaultViewports;
  }

  return configured.split(',').map((entry) => {
    const match = entry.trim().match(/^(?:(?<name>[a-z0-9-]+):)?(?<width>\d+)x(?<height>\d+)$/i);
    if (!match || !match.groups) {
      throw new Error(`Invalid viewport "${entry}". Use 390x844 or phone:390x844.`);
    }
    const width = Number(match.groups.width);
    const height = Number(match.groups.height);
    return {
      name: slug(match.groups.name || `${width}x${height}`),
      width,
      height,
      isMobile: width <= 768,
    };
  });
};

const parsePages = () => {
  const configured = String(process.env.AUDIT_PAGES || '').trim();
  if (!configured) {
    return defaultPages;
  }

  const requested = new Set(configured.split(',').map((value) => slug(value.trim())).filter(Boolean));
  return defaultPages.filter(([name]) => requested.has(slug(name)));
};

const fail = (message) => {
  console.error(`[responsive-ui] FAIL: ${message}`);
  process.exit(1);
};

const login = async (page) => {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForURL(/\/dashboard(?:$|\?)/, { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
};

const inspectLayout = async (page) => page.evaluate(() => {
  const viewportWidth = window.innerWidth;
  const documentWidth = document.documentElement.scrollWidth;
  const rootOverflow = Math.max(0, documentWidth - viewportWidth);
  const scrollSelectors = '.table-wrap,.data-table-scroll,.table-scroll,.summary-table-scroll,[data-horizontal-scroll]';
  const visible = (element) => {
    const style = window.getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity) !== 0 && rect.width > 0 && rect.height > 0;
  };
  const insideClosedSidebar = (element) => {
    const sidebar = element.closest('.sidebar');
    if (!sidebar) return false;
    const rect = sidebar.getBoundingClientRect();
    return sidebar.getAttribute('aria-hidden') === 'true' || rect.right <= 0 || rect.left >= viewportWidth;
  };

  const clippedControls = Array.from(document.querySelectorAll('button,a,input,select,textarea,summary'))
    .filter(visible)
    .filter((element) => !element.closest(scrollSelectors))
    .filter((element) => !insideClosedSidebar(element))
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        tag: element.tagName.toLowerCase(),
        text: String(element.getAttribute('aria-label') || element.textContent || element.getAttribute('name') || '').trim().slice(0, 80),
        left: Math.round(rect.left),
        right: Math.round(rect.right),
        width: Math.round(rect.width),
      };
    })
    .filter((control) => control.left < -2 || control.right > viewportWidth + 2)
    .slice(0, 20);

  const horizontalScrollers = Array.from(document.querySelectorAll(scrollSelectors))
    .filter(visible)
    .map((element) => ({
      className: String(element.className || '').slice(0, 120),
      clientWidth: element.clientWidth,
      scrollWidth: element.scrollWidth,
      overflowX: window.getComputedStyle(element).overflowX,
    }))
    .filter((entry) => entry.scrollWidth > entry.clientWidth + 1);

  const sidebar = document.querySelector('.sidebar');
  const nav = sidebar ? sidebar.querySelector('.nav-links') : null;
  const sidebarMetrics = sidebar && nav ? {
    sidebarOverflowY: window.getComputedStyle(sidebar).overflowY,
    navOverflowY: window.getComputedStyle(nav).overflowY,
    sidebarClientHeight: sidebar.clientHeight,
    sidebarScrollHeight: sidebar.scrollHeight,
    navClientHeight: nav.clientHeight,
    navScrollHeight: nav.scrollHeight,
  } : null;

  return {
    title: document.title,
    viewportWidth,
    documentWidth,
    rootOverflow,
    clippedControls,
    horizontalScrollers,
    sidebar: sidebarMetrics,
  };
});

const inspectSidebarInteraction = async (page, viewportWidth) => {
  if (viewportWidth > 1360) {
    return { status: 'not-applicable' };
  }

  const toggle = page.locator('[data-menu-toggle]').first();
  if (await toggle.count() === 0 || !await toggle.isVisible()) {
    return { status: 'missing-toggle' };
  }

  await toggle.click();
  await page.waitForTimeout(220);
  const result = await page.evaluate(() => {
    const shell = document.querySelector('.shell');
    const sidebar = document.querySelector('.sidebar');
    const nav = sidebar?.querySelector('.nav-links');
    if (!(shell instanceof HTMLElement) || !(sidebar instanceof HTMLElement) || !(nav instanceof HTMLElement)) {
      return { status: 'missing-shell' };
    }

    const navBefore = nav.scrollTop;
    const sidebarBefore = sidebar.scrollTop;
    const navMaxScroll = Math.max(0, nav.scrollHeight - nav.clientHeight);
    const sidebarMaxScroll = Math.max(0, sidebar.scrollHeight - sidebar.clientHeight);
    const navOverflowY = getComputedStyle(nav).overflowY;
    const sidebarOverflowY = getComputedStyle(sidebar).overflowY;
    const permitsScrolling = (value) => ['auto', 'scroll', 'overlay'].includes(value);

    if (navMaxScroll > 0) {
      nav.scrollTop = navMaxScroll;
    }
    if (sidebarMaxScroll > 0) {
      sidebar.scrollTop = sidebarMaxScroll;
    }

    const navAfter = nav.scrollTop;
    const sidebarAfter = sidebar.scrollTop;
    nav.scrollTop = navBefore;
    sidebar.scrollTop = sidebarBefore;
    const overflowRequired = navMaxScroll > 0 || sidebarMaxScroll > 0;
    const navScrollable = navMaxScroll > 0 && permitsScrolling(navOverflowY) && navAfter > 0;
    const sidebarScrollable = sidebarMaxScroll > 0 && permitsScrolling(sidebarOverflowY) && sidebarAfter > 0;

    return {
      status: 'checked',
      open: shell.classList.contains('nav-open'),
      sidebarVisible: sidebar.getBoundingClientRect().right > 0,
      navOverflowY,
      sidebarOverflowY,
      navClientHeight: nav.clientHeight,
      navScrollHeight: nav.scrollHeight,
      sidebarClientHeight: sidebar.clientHeight,
      sidebarScrollHeight: sidebar.scrollHeight,
      scrollOwner: navScrollable ? 'nav' : (sidebarScrollable ? 'sidebar' : null),
      scrollable: !overflowRequired || navScrollable || sidebarScrollable,
    };
  });

  await page.keyboard.press('Escape');
  await page.waitForTimeout(80);
  return result;
};

const inspectComboboxInteraction = async (page, pageName) => {
  if (pageName !== 'assets') {
    return { status: 'not-applicable' };
  }

  const toggle = page.locator('.assets-page .select-combobox-toggle').first();
  if (await toggle.count() === 0 || !await toggle.isVisible()) {
    return { status: 'not-present' };
  }

  const before = await toggle.evaluate((element) => {
    const container = element.closest('.filter-panel, [data-live-filter-region], .panel');
    return container instanceof HTMLElement ? container.getBoundingClientRect().height : null;
  });
  await toggle.click();
  await page.waitForTimeout(80);
  const result = await toggle.evaluate((element, beforeHeight) => {
    const combo = element.closest('.select-combobox');
    const panel = combo?.querySelector('.select-combobox-panel');
    const container = element.closest('.filter-panel, [data-live-filter-region], .panel');
    if (!(combo instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
      return { status: 'missing-panel' };
    }

    const panelRect = panel.getBoundingClientRect();
    const afterHeight = container instanceof HTMLElement ? container.getBoundingClientRect().height : null;
    return {
      status: 'checked',
      open: combo.classList.contains('is-open') && !panel.hidden,
      position: getComputedStyle(panel).position,
      containerHeightDelta: beforeHeight === null || afterHeight === null ? null : Math.round(afterHeight - beforeHeight),
      panelWithinHorizontalViewport: panelRect.left >= -2 && panelRect.right <= window.innerWidth + 2,
      panelMaxHeight: getComputedStyle(panel).maxHeight,
    };
  }, before);

  await page.keyboard.press('Escape');
  return result;
};

const inspectRowActionInteraction = async (page, pageName) => {
  if (pageName !== 'items') {
    return { status: 'not-applicable' };
  }

  const summary = page.locator('details.row-action-menu summary').first();
  if (await summary.count() === 0 || !await summary.isVisible()) {
    return { status: 'not-present' };
  }

  await summary.click();
  await page.waitForTimeout(80);
  const result = await summary.evaluate((element) => {
    const menu = element.closest('details.row-action-menu');
    const list = menu?.querySelector('.row-action-list');
    if (!(menu instanceof HTMLDetailsElement) || !(list instanceof HTMLElement)) {
      return { status: 'missing-menu' };
    }
    const rect = list.getBoundingClientRect();
    return {
      status: 'checked',
      open: menu.open,
      visible: getComputedStyle(list).display !== 'none' && rect.width > 0 && rect.height > 0,
      withinHorizontalViewport: rect.left >= -2 && rect.right <= window.innerWidth + 2,
      position: getComputedStyle(list).position,
    };
  });

  await page.keyboard.press('Escape');
  return result;
};

(async () => {
  const pages = parsePages();
  const viewports = parseViewports();
  const results = [];
  const failures = [];

  if (pages.length === 0) {
    throw new Error('No matching pages were selected for the responsive audit.');
  }

  fs.mkdirSync(outputDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });

  try {
    for (const viewport of viewports) {
      const viewportDir = path.join(outputDir, viewport.name);
      fs.mkdirSync(viewportDir, { recursive: true });

      const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        deviceScaleFactor: viewport.isMobile ? 2 : 1,
        isMobile: viewport.isMobile,
        hasTouch: viewport.isMobile,
        userAgent: `InventoryResponsiveAudit/2.0 (${viewport.name})`,
      });
      const page = await context.newPage();
      const consoleErrors = [];
      const pageErrors = [];

      page.on('console', (message) => {
        if (message.type() === 'error') {
          consoleErrors.push(message.text());
        }
      });
      page.on('pageerror', (error) => pageErrors.push(error.message || String(error)));

      try {
        await login(page);

        for (const [name, route] of pages) {
          consoleErrors.length = 0;
          pageErrors.length = 0;
          const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
          const status = response ? response.status() : 0;
          const layout = await inspectLayout(page);
          const interactions = {
            sidebar: await inspectSidebarInteraction(page, viewport.width),
            combobox: await inspectComboboxInteraction(page, name),
            rowActions: await inspectRowActionInteraction(page, name),
          };
          const record = {
            viewport: viewport.name,
            width: viewport.width,
            height: viewport.height,
            name,
            route,
            finalUrl: page.url(),
            status,
            consoleErrors: [...consoleErrors],
            pageErrors: [...pageErrors],
            layout,
            interactions,
          };
          results.push(record);

          if (screenshotMode) {
            await page.screenshot({
              path: path.join(viewportDir, `${slug(name)}.png`),
              fullPage: true,
            });
          }

          if (status >= 400) {
            failures.push(`${viewport.name} ${route} returned HTTP ${status}`);
          }
          if (/\/login(?:$|\?)/.test(new URL(page.url()).pathname)) {
            failures.push(`${viewport.name} ${route} redirected to login`);
          }
          if (layout.rootOverflow > 1) {
            failures.push(`${viewport.name} ${route} has ${layout.rootOverflow}px root horizontal overflow`);
          }
          if (layout.clippedControls.length > 0) {
            failures.push(`${viewport.name} ${route} has ${layout.clippedControls.length} clipped controls`);
          }
          if (consoleErrors.length > 0 || pageErrors.length > 0) {
            failures.push(`${viewport.name} ${route} emitted browser errors`);
          }
          if (interactions.sidebar.status === 'checked' && (!interactions.sidebar.open || !interactions.sidebar.sidebarVisible || !interactions.sidebar.scrollable)) {
            failures.push(`${viewport.name} ${route} has a broken mobile sidebar drawer or scroll region`);
          }
          if (interactions.combobox.status === 'checked' && (!interactions.combobox.open || interactions.combobox.position !== 'absolute' || Math.abs(interactions.combobox.containerHeightDelta || 0) > 4 || !interactions.combobox.panelWithinHorizontalViewport)) {
            failures.push(`${viewport.name} ${route} has a combobox that pushes or clips the layout`);
          }
          if (interactions.rowActions.status === 'checked' && (!interactions.rowActions.open || !interactions.rowActions.visible || !interactions.rowActions.withinHorizontalViewport)) {
            failures.push(`${viewport.name} ${route} has a clipped or unusable row action menu`);
          }

          const clipped = layout.clippedControls.length > 0 ? `; clipped controls ${layout.clippedControls.length}` : '';
          console.log(`[responsive-ui] ${viewport.name} ${route} (${status}); root overflow ${layout.rootOverflow}px${clipped}`);
        }
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }

  const summaryPath = path.join(outputDir, 'audit-results.json');
  fs.writeFileSync(summaryPath, `${JSON.stringify({ generatedAt: new Date().toISOString(), baseUrl, failures, results }, null, 2)}\n`);
  console.log(`[responsive-ui] wrote ${summaryPath}`);

  if (failures.length > 0) {
    failures.forEach((message) => console.error(`[responsive-ui] ${message}`));
    process.exit(1);
  }
})().catch((error) => {
  fail(error.message || String(error));
});
