import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

async function loadChromium() {
  try {
    const mod = await import('playwright-core');
    return mod.chromium;
  } catch {
    const mod = require('/tmp/cc-ui-validation/node_modules/playwright-core');
    return mod.chromium;
  }
}

const chromium = await loadChromium();

const BASE = 'http://acf-plugin.local';
const USER = 'codex_validate';
const PASS = 'Codex!2026Validate';

const ROOT = process.cwd();
const DOCS_DIR = path.join(ROOT, 'docs');
const VALIDATION_DIR = path.join(DOCS_DIR, 'validation-output', 'latest');
const SHOTS = path.join(VALIDATION_DIR, 'screenshots');
const MARKDOWN_REPORT = path.join(DOCS_DIR, 'admin-ui-validation.md');

await fs.mkdir(SHOTS, { recursive: true });

async function readExtraThirdPartyUrls() {
  const fromEnv = (process.env.CC_UI_THIRD_PARTY_URLS || '')
    .split(/[\n,]/)
    .map((v) => v.trim())
    .filter(Boolean);

  const filePath = (process.env.CC_UI_THIRD_PARTY_FILE || '').trim();
  let fromFile = [];
  if (filePath) {
    try {
      const raw = await fs.readFile(filePath, 'utf8');
      fromFile = raw
        .split(/[\n,]/)
        .map((v) => v.trim())
        .filter(Boolean);
    } catch {
      // keep validation running even when optional file is missing
    }
  }

  return Array.from(
    new Set([...fromEnv, ...fromFile].filter((url) => url.includes('/wp-admin/')))
  );
}

function relFromDocs(file) {
  return path.relative(DOCS_DIR, file).replaceAll('\\\\', '/');
}

function parsePx(value) {
  if (typeof value !== 'string') return 0;
  const n = Number.parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

function styleChanged(before, after, key, threshold = 2) {
  const a = before?.[key];
  const b = after?.[key];
  if (typeof a === 'undefined' || typeof b === 'undefined') return false;
  if (a === b) return false;
  const na = parsePx(a);
  const nb = parsePx(b);
  if (Number.isFinite(na) && Number.isFinite(nb) && (a.endsWith?.('px') || b.endsWith?.('px'))) {
    return Math.abs(na - nb) >= threshold;
  }
  return true;
}

function computeVisualChangeScore(before, after) {
  let score = 0;

  if (styleChanged(before?.button, after?.button, 'minHeight', 2)) score += 1;
  if (styleChanged(before?.button, after?.button, 'borderRadius', 1)) score += 1;
  if (styleChanged(before?.button, after?.button, 'paddingLeft', 2)) score += 1;
  if (styleChanged(before?.button, after?.button, 'backgroundColor', 0)) score += 1;

  if (styleChanged(before?.input, after?.input, 'minHeight', 2)) score += 1;
  if (styleChanged(before?.input, after?.input, 'borderRadius', 1)) score += 1;

  if (styleChanged(before?.tableHead, after?.tableHead, 'paddingTop', 2)) score += 1;
  if (styleChanged(before?.tableHead, after?.tableHead, 'textTransform', 0)) score += 1;

  if (styleChanged(before?.postbox, after?.postbox, 'borderRadius', 1)) score += 1;
  if (styleChanged(before?.postboxInside, after?.postboxInside, 'paddingTop', 2)) score += 1;

  if (styleChanged(before?.formTableTh, after?.formTableTh, 'display', 0)) score += 1;
  if (styleChanged(before?.mediaAttachments, after?.mediaAttachments, 'display', 0)) score += 1;

  if (styleChanged(before?.sidebarItem, after?.sidebarItem, 'minHeight', 1)) score += 1;
  if (styleChanged(before?.sidebarItem, after?.sidebarItem, 'borderRadius', 1)) score += 1;

  return score;
}

const browser = await chromium.launch({
  headless: true,
  executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  args: ['--disable-dev-shm-usage', '--no-first-run', '--no-default-browser-check']
});

const context = await browser.newContext({ viewport: { width: 1720, height: 1100 } });
const loginPage = await context.newPage();

async function saveShot(page, file) {
  const target = path.join(SHOTS, file);
  await page.screenshot({ path: target, fullPage: true });
  return target;
}

async function setUnifiedLayerState(page, enabled) {
  await page.evaluate((isEnabled) => {
    const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
    const unified = links.filter((l) => (l.href || '').includes('/assets/css/admin-theme/index.css'));
    for (const link of unified) {
      if (isEnabled) {
        link.disabled = false;
      } else {
        link.disabled = true;
      }
    }

    if (isEnabled) {
      if (!document.body.classList.contains('cc-admin-theme')) {
        document.body.classList.add('cc-admin-theme');
      }
    } else {
      document.body.classList.remove('cc-admin-theme');
    }
  }, enabled);
}

async function collectPrimitiveSnapshot(page) {
  return page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    const pick = (el, props) => {
      if (!el) return null;
      const cs = getComputedStyle(el);
      const out = {};
      for (const p of props) out[p] = cs[p];
      return out;
    };

    return {
      button: pick(q('.wp-core-ui .button, .button'), ['minHeight', 'height', 'borderRadius', 'paddingLeft', 'paddingRight', 'backgroundColor']),
      input: pick(q('input[type="text"], input.regular-text, select, textarea'), ['minHeight', 'height', 'borderRadius', 'paddingLeft', 'paddingRight', 'backgroundColor']),
      tableHead: pick(q('.wp-list-table thead th, .widefat thead th'), ['textTransform', 'fontSize', 'paddingTop', 'paddingLeft']),
      postbox: pick(q('.postbox, .dashboard-widget'), ['borderRadius', 'borderTopColor', 'backgroundColor']),
      postboxInside: pick(q('.postbox .inside, .dashboard-widget .inside'), ['paddingTop', 'paddingLeft']),
      formTableTh: pick(q('.form-table th'), ['display', 'marginBottom', 'paddingTop', 'paddingBottom']),
      mediaAttachments: pick(q('.media-frame .attachments, .attachments-browser .attachments'), ['display', 'gap']),
      sidebarItem: pick(q('#adminmenu li.menu-top > a.menu-top'), ['minHeight', 'height', 'borderRadius', 'paddingLeft', 'paddingRight'])
    };
  });
}

async function login() {
  await loginPage.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await loginPage.fill('#user_login', USER);
  await loginPage.fill('#user_pass', PASS);
  await loginPage.click('#wp-submit');
  await loginPage.waitForURL(/\/wp-admin\//, { timeout: 30000 });
}

const coreScreens = [
  { key: 'core-dashboard', label: 'Dashboard', url: `${BASE}/wp-admin/index.php` },
  { key: 'core-posts-list', label: 'Posts list', url: `${BASE}/wp-admin/edit.php` },
  { key: 'core-pages-list', label: 'Pages list', url: `${BASE}/wp-admin/edit.php?post_type=page` },
  { key: 'core-post-editor', label: 'Post editor', url: `${BASE}/wp-admin/post-new.php` },
  { key: 'core-media-library', label: 'Media library', url: `${BASE}/wp-admin/upload.php` },
  { key: 'core-users', label: 'Users list', url: `${BASE}/wp-admin/users.php` },
  { key: 'core-plugins', label: 'Plugins page', url: `${BASE}/wp-admin/plugins.php` },
  { key: 'core-settings-general', label: 'Settings general', url: `${BASE}/wp-admin/options-general.php` },
  { key: 'core-settings-writing', label: 'Settings writing', url: `${BASE}/wp-admin/options-writing.php` },
  { key: 'core-settings-reading', label: 'Settings reading', url: `${BASE}/wp-admin/options-reading.php` },
  { key: 'core-settings-discussion', label: 'Settings discussion', url: `${BASE}/wp-admin/options-discussion.php` },
  { key: 'core-settings-media', label: 'Settings media', url: `${BASE}/wp-admin/options-media.php` },
  { key: 'core-settings-permalinks', label: 'Settings permalinks', url: `${BASE}/wp-admin/options-permalink.php` },
  { key: 'core-tools', label: 'Tools page', url: `${BASE}/wp-admin/tools.php` },
  { key: 'core-appearance', label: 'Appearance page', url: `${BASE}/wp-admin/themes.php` }
];

const pluginScreens = [
  { key: 'cc-dashboard', label: 'Content Core dashboard', url: `${BASE}/wp-admin/admin.php?page=content-core` },
  { key: 'cc-site-settings', label: 'Site settings', url: `${BASE}/wp-admin/admin.php?page=cc-site-options` },
  { key: 'cc-seo', label: 'SEO', url: `${BASE}/wp-admin/admin.php?page=cc-seo` },
  { key: 'cc-branding', label: 'Branding', url: `${BASE}/wp-admin/admin.php?page=cc-branding` },
  { key: 'cc-site-images', label: 'Site images', url: `${BASE}/wp-admin/admin.php?page=cc-media` },
  { key: 'cc-cookie-banner', label: 'Cookie banner', url: `${BASE}/wp-admin/admin.php?page=cc-cookie-banner` },
  { key: 'cc-diagnostics', label: 'Diagnostics', url: `${BASE}/wp-admin/admin.php?page=cc-diagnostics` },
  { key: 'cc-terms-manager', label: 'Terms manager', url: `${BASE}/wp-admin/admin.php?page=cc-manage-terms` },
  { key: 'cc-forms-builder', label: 'Forms builder', url: `${BASE}/wp-admin/edit.php?post_type=cc_form` },
  { key: 'cc-custom-fields', label: 'Custom fields', url: `${BASE}/wp-admin/edit.php?post_type=cc_field_group` },
  { key: 'cc-options-pages', label: 'Options pages', url: `${BASE}/wp-admin/edit.php?post_type=cc_options_page` }
];

async function inspectScreen(spec) {
  const page = await context.newPage();
  const jsErrors = [];
  const consoleErrors = [];

  page.on('pageerror', (err) => jsErrors.push(String(err)));
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    if (text.startsWith('Failed to load resource')) return;
    consoleErrors.push(text);
  });

  const t0 = Date.now();
  let status = 'ok';
  let reason = '';
  try {
    const resp = await page.goto(spec.url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    if (!resp || !resp.ok()) {
      status = 'http_error';
      reason = `HTTP ${resp ? resp.status() : 'no_response'}`;
    }
    await page.waitForTimeout(900);
  } catch (err) {
    status = 'nav_error';
    reason = String(err);
  }
  const loadMs = Date.now() - t0;

  await setUnifiedLayerState(page, false);
  await page.waitForTimeout(250);
  const beforeSnapshot = await collectPrimitiveSnapshot(page);
  const beforeScreenshot = await saveShot(page, `${spec.key}-before.png`);

  await setUnifiedLayerState(page, true);
  await page.waitForTimeout(250);

  const analysis = await page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    const qa = (s) => document.querySelectorAll(s).length;

    const selectorFor = (el) => {
      const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
      return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${cls ? '.' + cls : ''}`;
    };

    const isVisible = (el) => !!el && !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);

    const pickStyles = (el, props) => {
      if (!el) return null;
      const cs = getComputedStyle(el);
      const out = {};
      for (const p of props) out[p] = cs[p];
      return out;
    };

    const px = (value) => {
      const n = Number.parseFloat(value || '0');
      return Number.isFinite(n) ? n : 0;
    };

    const assessDominance = (present, checks) => {
      if (!present) return { present: false, status: 'not_present', reasons: [] };
      const failed = checks.filter((c) => !c.ok).map((c) => c.reason);
      if (failed.length) return { present: true, status: 'dominating', reasons: failed };
      return { present: true, status: 'replaced', reasons: [] };
    };

    const contractStatus = (active, reason, na = false) => {
      if (na) return { status: 'na', reason };
      return active ? { status: 'active', reason } : { status: 'failed', reason };
    };

    const sampleBtn = q('.wp-core-ui .button, .button');
    const sampleInput = q('input[type="text"], input.regular-text, select, textarea');
    const wrapEl = q('.wrap');
    const headingEl = q('.wp-heading-inline, .wrap > h1');
    const toolbarEl = q('.tablenav.top, .cc-toolbar, .page-toolbar');
    const tablenavTopEl = q('.tablenav.top');
    const subsubsubEl = q('.subsubsub');
    const cardEl = q('.postbox, .cc-card, .ui-card, .stuffbox, .card, .dashboard-widget');
    const tableHeadEl = q('.wp-list-table thead th, .widefat thead th');
    const tableRowEl = q('.wp-list-table tbody tr, .widefat tbody tr');
    const tableCellEl = q('.wp-list-table tbody td, .widefat tbody td');
    const formTableEl = q('.form-table');
    const formTableThEl = q('.form-table th');
    const mediaFrameEl = q('.media-frame');
    const mediaAttachmentsEl = q('.media-frame .attachments, .attachments-browser .attachments');
    const mediaTileEl = q('.media-frame .attachment, .attachments .attachment');
    const mediaThumbEl = q('.media-frame .attachment .thumbnail, .attachments .attachment .thumbnail');
    const sidebarEl = q('#adminmenu');
    const sidebarItemEl = q('#adminmenu li.menu-top > a.menu-top');
    const activeMenuItemEl = q('#adminmenu li.current > a.menu-top, #adminmenu li.wp-has-current-submenu > a.menu-top');
    const dashboardWidgetEl = q('.dashboard-widget, #dashboard-widgets .postbox');
    const postboxInsideEl = q('.postbox .inside, .dashboard-widget .inside');
    const postboxEl = q('.postbox');

    const btnStyle = sampleBtn ? getComputedStyle(sampleBtn) : null;
    const inputStyle = sampleInput ? getComputedStyle(sampleInput) : null;
    const toolbarStyle = toolbarEl ? getComputedStyle(toolbarEl) : null;
    const cardStyle = cardEl ? getComputedStyle(cardEl) : null;
    const tableHeadStyle = tableHeadEl ? getComputedStyle(tableHeadEl) : null;
    const tableCellStyle = tableCellEl ? getComputedStyle(tableCellEl) : null;
    const tableRowStyle = tableRowEl ? getComputedStyle(tableRowEl) : null;
    const formLabelStyle = formTableThEl ? getComputedStyle(formTableThEl) : null;
    const sidebarItemStyle = sidebarItemEl ? getComputedStyle(sidebarItemEl) : null;
    const activeMenuStyle = activeMenuItemEl ? getComputedStyle(activeMenuItemEl) : null;
    const mediaAttachmentsStyle = mediaAttachmentsEl ? getComputedStyle(mediaAttachmentsEl) : null;
    const dashboardWidgetStyle = dashboardWidgetEl ? getComputedStyle(dashboardWidgetEl) : null;
    const postboxInsideStyle = postboxInsideEl ? getComputedStyle(postboxInsideEl) : null;
    const postboxStyle = postboxEl ? getComputedStyle(postboxEl) : null;

    const wpDominance = {
      '.wp-core-ui .button': assessDominance(!!sampleBtn, [
        { ok: px(btnStyle?.minHeight || btnStyle?.height) >= 40, reason: 'height < 40px' },
        { ok: px(btnStyle?.borderRadius) >= 8, reason: 'radius < 8px' },
        { ok: px(btnStyle?.paddingLeft) >= 12, reason: 'horizontal padding too small' }
      ]),
      '.wp-list-table': assessDominance(!!tableHeadEl, [
        { ok: (tableHeadStyle?.textTransform || '').toLowerCase() === 'uppercase', reason: 'thead not uppercase' },
        { ok: px(tableHeadStyle?.paddingTop) >= 10, reason: 'thead padding too small' },
        { ok: px(tableCellStyle?.paddingLeft) >= 12, reason: 'cell padding too small' },
        { ok: px(tableRowStyle?.minHeight || tableRowStyle?.height) >= 56, reason: 'row height too small' }
      ]),
      '.postbox': assessDominance(!!postboxEl, [
        { ok: !postboxStyle || px(postboxStyle.borderRadius) >= 10, reason: 'postbox radius < 10px' },
        { ok: !postboxInsideStyle || px(postboxInsideStyle.paddingTop) >= 20, reason: 'postbox inside padding too small' }
      ]),
      '.form-table': assessDominance(!!formTableEl, [
        { ok: !formLabelStyle || formLabelStyle.display === 'block', reason: 'label still table-cell' },
        { ok: !inputStyle || px(inputStyle.minHeight || inputStyle.height) >= 40, reason: 'input height < 40px' },
        { ok: !inputStyle || px(inputStyle.borderRadius) >= 8, reason: 'input radius < 8px' }
      ]),
      '.media-frame': assessDominance(!!mediaFrameEl, [
        { ok: !mediaAttachmentsStyle || mediaAttachmentsStyle.display === 'grid', reason: 'media tiles not grid' },
        { ok: !mediaAttachmentsStyle || px(mediaAttachmentsStyle.gap) >= 12, reason: 'media grid gap too small' },
        { ok: !mediaTileEl || px(getComputedStyle(mediaTileEl).borderRadius) >= 8, reason: 'media tile radius too small' }
      ]),
      '.dashboard-widget': assessDominance(!!dashboardWidgetEl, [
        { ok: !dashboardWidgetStyle || px(dashboardWidgetStyle.borderRadius) >= 10, reason: 'dashboard widget radius < 10px' },
        { ok: !postboxInsideStyle || px(postboxInsideStyle.paddingTop) >= 20, reason: 'dashboard widget padding too small' }
      ])
    };

    const wpDominatingComponents = Object.entries(wpDominance)
      .filter(([, state]) => state.status === 'dominating')
      .map(([name, state]) => `${name}: ${state.reasons.join(', ')}`);

    const wpDefaultStylesVisible = wpDominatingComponents.length > 0;

    const buttonContract = sampleBtn
      ? contractStatus(
          px(btnStyle.minHeight || btnStyle.height) >= 40 && px(btnStyle.borderRadius) >= 8,
          'button metrics and radius'
        )
      : contractStatus(false, 'no button on screen', true);

    const cardContract = cardEl
      ? contractStatus(
          px(cardStyle.borderRadius) >= 10 && cardStyle.borderStyle !== 'none',
          'card radius and border'
        )
      : contractStatus(false, 'no card element on screen', true);

    const tableContract = tableHeadEl
      ? contractStatus(
          (tableHeadStyle.textTransform || '').toLowerCase() === 'uppercase' &&
            px(tableHeadStyle.paddingTop) >= 10 &&
            (!tableCellStyle || px(tableCellStyle.paddingLeft) >= 12),
          'table head and row density'
        )
      : contractStatus(false, 'no table head on screen', true);

    const formContract = sampleInput
      ? contractStatus(
          px(inputStyle.minHeight || inputStyle.height) >= 40 &&
            px(inputStyle.borderRadius) >= 8 &&
            (!formLabelStyle || (formLabelStyle.display === 'block' && px(formLabelStyle.marginBottom) >= 6)),
          'field metrics and label rhythm'
        )
      : contractStatus(false, 'no form controls on screen', true);

    const toolbarContract = toolbarEl
      ? contractStatus(
          (toolbarStyle.display === 'flex' || toolbarStyle.display === 'inline-flex') &&
            px(toolbarStyle.borderRadius) >= 10,
          'toolbar layout and container'
        )
      : contractStatus(false, 'no toolbar on screen', true);

    const menuContract = sidebarItemEl
      ? contractStatus(
          px(sidebarItemStyle.minHeight || sidebarItemStyle.height) >= 40 &&
            px(sidebarItemStyle.borderRadius) >= 8 &&
            (!activeMenuStyle || activeMenuStyle.backgroundColor !== 'rgba(0, 0, 0, 0)'),
          'sidebar item and active state'
        )
      : contractStatus(false, 'no sidebar item found', false);

    const viewportOverflowX = Math.max(
      0,
      document.documentElement.scrollWidth - document.documentElement.clientWidth,
      document.body.scrollWidth - document.body.clientWidth
    );

    const overflowsUnexpected = Array.from(document.querySelectorAll('body *'))
      .filter((el) => isVisible(el))
      .filter((el) => el.clientWidth > 0 && el.scrollWidth - el.clientWidth > 4)
      .filter((el) => !el.closest('#adminmenuwrap, #adminmenu'))
      .slice(0, 12)
      .map((el) => `${selectorFor(el)} (+${el.scrollWidth - el.clientWidth}px)`);

    const contentChildren = Array.from(document.querySelectorAll('.wrap > *'))
      .filter((el) => isVisible(el))
      .filter((el) => !el.matches('.wp-header-end'))
      .filter((el) => !headingEl || el !== headingEl)
      .filter((el) => !el.matches('.page-title-action, .add-new-h2'));

    const firstContentEl = contentChildren[0] || null;
    const headingRect = headingEl ? headingEl.getBoundingClientRect() : null;
    const firstContentRect = firstContentEl ? firstContentEl.getBoundingClientRect() : null;
    const spacingGap = (headingRect && firstContentRect) ? (firstContentRect.top - headingRect.bottom) : null;

    const incorrectSpacingRhythm = [];
    if (spacingGap !== null && (spacingGap < -4 || spacingGap > 56)) {
      incorrectSpacingRhythm.push(`heading-to-content gap ${Math.round(spacingGap)}px`);
    }

    const isBlockEditor = document.body.classList.contains('block-editor-page') || !!q('.edit-post-layout');
    const blockCanvasOk = isBlockEditor ? !!q('.block-editor-writing-flow, .editor-styles-wrapper') : null;

    const visualPrimitives = {
      button: pickStyles(sampleBtn, ['minHeight', 'height', 'borderRadius', 'paddingLeft', 'paddingRight', 'backgroundColor']),
      input: pickStyles(sampleInput, ['minHeight', 'height', 'borderRadius', 'paddingLeft', 'paddingRight', 'backgroundColor']),
      tableHead: pickStyles(tableHeadEl, ['textTransform', 'fontSize', 'paddingTop', 'paddingLeft']),
      postbox: pickStyles(q('.postbox, .dashboard-widget'), ['borderRadius', 'borderTopColor', 'backgroundColor']),
      postboxInside: pickStyles(q('.postbox .inside, .dashboard-widget .inside'), ['paddingTop', 'paddingLeft']),
      formTableTh: pickStyles(formTableThEl, ['display', 'marginBottom', 'paddingTop', 'paddingBottom']),
      mediaAttachments: pickStyles(mediaAttachmentsEl, ['display', 'gap']),
      sidebarItem: pickStyles(sidebarItemEl, ['minHeight', 'height', 'borderRadius', 'paddingLeft', 'paddingRight'])
    };

    return {
      title: document.title,
      url: location.href,
      bodyClasses: Array.from(document.body.classList),
      hasUnifiedClass: document.body.classList.contains('cc-admin-theme'),
      cssLinks: Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((l) => l.href),
      counts: {
        buttons: qa('.button, .wp-core-ui .button'),
        cards: qa('.cc-card, .ui-card, .postbox, .card, .dashboard-widget'),
        tables: qa('table.wp-list-table, table.widefat, .cc-data-grid, .ui-datagrid, table'),
        notices: qa('.notice, .cc-tm-notice, .ui-notice'),
        forms: qa('form'),
        inputs: qa('input, select, textarea')
      },
      styleAudit: {
        wrap: pickStyles(wrapEl, ['maxWidth', 'marginTop', 'marginLeft', 'marginRight', 'paddingLeft', 'paddingRight']),
        heading: pickStyles(headingEl, ['fontSize', 'lineHeight', 'fontWeight', 'marginBottom']),
        toolbar: pickStyles(toolbarEl, ['display', 'gap', 'paddingTop', 'paddingLeft', 'borderRadius', 'backgroundColor']),
        tablenavTop: pickStyles(tablenavTopEl, ['display', 'paddingTop', 'paddingLeft', 'borderRadius', 'backgroundColor']),
        subsubsub: pickStyles(subsubsubEl, ['display', 'gap', 'marginTop', 'paddingLeft']),
        card: pickStyles(cardEl, ['borderRadius', 'paddingTop', 'backgroundColor', 'borderTopColor']),
        tableHead: pickStyles(tableHeadEl, ['fontSize', 'letterSpacing', 'textTransform', 'height', 'paddingTop', 'paddingLeft']),
        mediaTile: pickStyles(mediaTileEl, ['width', 'marginTop', 'borderRadius']),
        mediaThumb: pickStyles(mediaThumbEl, ['width', 'maxWidth', 'overflowX', 'position']),
        formTable: pickStyles(formTableEl, ['display', 'borderRadius', 'marginTop']),
        formTableTh: pickStyles(formTableThEl, ['display', 'marginBottom', 'fontSize', 'fontWeight']),
        sidebar: pickStyles(sidebarEl, ['paddingTop', 'backgroundColor', 'width']),
        sidebarItem: pickStyles(sidebarItemEl, ['display', 'height', 'minHeight', 'paddingLeft', 'paddingRight', 'borderRadius'])
      },
      dominanceContracts: {
        button: buttonContract,
        card: cardContract,
        table: tableContract,
        form: formContract,
        toolbar: toolbarContract,
        menu: menuContract
      },
      wpDominance,
      wpDominatingComponents,
      wpDefaultStylesVisible,
      visualPrimitives,
      overflowsUnexpected,
      viewportOverflowX,
      isBlockEditor,
      blockCanvasOk,
      layoutBreakage: {
        incorrectSpacingRhythm
      }
    };
  });

  const afterScreenshot = await saveShot(page, `${spec.key}.png`);
  await page.close();

  const visualChangeScore = computeVisualChangeScore(beforeSnapshot, analysis.visualPrimitives);
  const visuallyChanged = visualChangeScore >= 3;

  const contracts = analysis.dominanceContracts || {};
  const failedContracts = Object.entries(contracts)
    .filter(([, v]) => v?.status === 'failed')
    .map(([name]) => name);

  const visualAssessment = (() => {
    if (!analysis.hasUnifiedClass) return 'not_unified';
    if (analysis.wpDefaultStylesVisible) return 'not_unified';
    if (failedContracts.length) return 'partially_unified';
    if (!visuallyChanged) return 'partially_unified';
    return 'unified';
  })();

  return {
    ...spec,
    status,
    reason,
    loadMs,
    analysis,
    jsErrors,
    consoleErrors,
    beforeSnapshot,
    visualChangeScore,
    visuallyChanged,
    visualAssessment,
    beforeScreenshot,
    screenshot: afterScreenshot
  };
}

function buildMarkdownReport(all, meta) {
  const lines = [];
  const nowIso = new Date().toISOString();

  const summary = {
    unified: all.filter((r) => r.visualAssessment === 'unified').length,
    partial: all.filter((r) => r.visualAssessment === 'partially_unified').length,
    notUnified: all.filter((r) => r.visualAssessment === 'not_unified').length,
    wpVisible: all.filter((r) => r.analysis?.wpDefaultStylesVisible).length
  };

  lines.push('# Admin UI Validation');
  lines.push('');
  lines.push(`Generated: ${nowIso}`);
  lines.push(`Base URL: ${BASE}`);
  lines.push(`Screens tested: ${all.length} (core ${meta.coreCount}, plugin ${meta.pluginCount}, third-party ${meta.thirdPartyCount})`);
  lines.push(`Visual summary: unified ${summary.unified}, partially unified ${summary.partial}, not unified ${summary.notUnified}`);
  lines.push(`WP Default Styles sichtbar on screens: ${summary.wpVisible}`);
  lines.push('');
  lines.push('## Screen Matrix');
  lines.push('');
  lines.push('| Screen | Visual assessment | WP Default Styles sichtbar | Visual delta |');
  lines.push('| --- | --- | --- | --- |');
  for (const r of all) {
    lines.push(`| ${r.key} | ${r.visualAssessment} | ${r.analysis.wpDefaultStylesVisible ? 'yes' : 'no'} | ${r.visualChangeScore} |`);
  }
  lines.push('');

  for (const r of all) {
    const contracts = r.analysis.dominanceContracts || {};
    const wpDominance = r.analysis.wpDominance || {};
    const wpStillVisible = r.analysis.wpDominatingComponents || [];

    lines.push(`## ${r.key} - ${r.label}`);
    lines.push('');
    lines.push(`URL: ${r.url}`);
    lines.push(`Visual assessment: ${r.visualAssessment}`);
    lines.push(`WP Default Styles sichtbar: ${r.analysis.wpDefaultStylesVisible ? 'yes' : 'no'}`);
    lines.push(`Visual delta (before vs after): ${r.visualChangeScore}`);
    lines.push(`Body class cc-admin-theme active: ${r.analysis.hasUnifiedClass ? 'yes' : 'no'}`);
    lines.push(`Layout overflow unexpected count: ${(r.analysis.overflowsUnexpected || []).length}`);
    lines.push('');

    lines.push('Contracts');
    lines.push(`- Button contract: ${contracts.button?.status || 'na'} (${contracts.button?.reason || ''})`);
    lines.push(`- Table contract: ${contracts.table?.status || 'na'} (${contracts.table?.reason || ''})`);
    lines.push(`- Card contract: ${contracts.card?.status || 'na'} (${contracts.card?.reason || ''})`);
    lines.push(`- Form contract: ${contracts.form?.status || 'na'} (${contracts.form?.reason || ''})`);
    lines.push(`- Toolbar contract: ${contracts.toolbar?.status || 'na'} (${contracts.toolbar?.reason || ''})`);
    lines.push(`- Menu contract: ${contracts.menu?.status || 'na'} (${contracts.menu?.reason || ''})`);
    lines.push('');

    lines.push('WP core class dominance');
    for (const cls of ['.wp-core-ui .button', '.wp-list-table', '.postbox', '.form-table', '.media-frame', '.dashboard-widget']) {
      const item = wpDominance[cls] || { status: 'not_present', reasons: [] };
      lines.push(`- ${cls}: ${item.status}${item.reasons?.length ? ` (${item.reasons.join(', ')})` : ''}`);
    }
    lines.push('');

    lines.push('WP components still visibly dominant');
    if (!wpStillVisible.length) {
      lines.push('- none');
    } else {
      for (const item of wpStillVisible) lines.push(`- ${item}`);
    }
    lines.push('');

    lines.push('Screenshots');
    lines.push(`Before:`);
    lines.push(`![${r.key} before](${relFromDocs(r.beforeScreenshot)})`);
    lines.push('');
    lines.push('After:');
    lines.push(`![${r.key} after](${relFromDocs(r.screenshot)})`);
    lines.push('');
    lines.push('---');
    lines.push('');
  }

  return lines.join('\n');
}

await login();

const menuPage = await context.newPage();
await menuPage.goto(`${BASE}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
const menuDiscovery = await menuPage.evaluate(() => {
  const links = Array.from(document.querySelectorAll('#adminmenu a[href]')).map((a) => a.href);
  const dedup = Array.from(new Set(links));

  const thirdPartyCandidates = dedup.filter((href) => {
    if (!href.includes('/wp-admin/admin.php?page=')) return false;
    if (href.includes('page=content-core')) return false;
    if (href.includes('page=cc-')) return false;
    return true;
  });

  const contentCoreCandidates = dedup.filter((href) => {
    if (!href.includes('/wp-admin/')) return false;
    if (href.includes('/wp-admin/admin.php?page=content-core')) return true;
    if (href.includes('/wp-admin/admin.php?page=cc-')) return true;
    if (href.includes('/wp-admin/edit.php?post_type=cc_')) return true;
    return false;
  });

  return { thirdPartyCandidates, contentCoreCandidates };
});
await menuPage.close();

const thirdPartyProvidedUrls = await readExtraThirdPartyUrls();
const thirdPartyUrls = Array.from(new Set([...(menuDiscovery.thirdPartyCandidates || []), ...thirdPartyProvidedUrls]));
const thirdPartyScreens = thirdPartyUrls.slice(0, 8).map((url, idx) => ({
  key: `third-party-${idx + 1}`,
  label: `Third-party page ${idx + 1}`,
  url
}));

const existingPluginUrls = new Set(pluginScreens.map((s) => s.url));
const discoveredContentCoreUrls = Array.from(new Set((menuDiscovery.contentCoreCandidates || [])))
  .filter((url) => !existingPluginUrls.has(url));
const discoveredContentCoreScreens = discoveredContentCoreUrls.map((url, idx) => ({
  key: `cc-extra-${idx + 1}`,
  label: `Content Core extra ${idx + 1}`,
  url
}));

const all = [];
for (const screen of [...coreScreens, ...pluginScreens, ...discoveredContentCoreScreens, ...thirdPartyScreens]) {
  all.push(await inspectScreen(screen));
}

const issues = [];
for (const r of all) {
  if (r.status !== 'ok') issues.push({ screen: r.key, type: 'navigation', detail: r.reason || r.status });
  if (!r.analysis.hasUnifiedClass) issues.push({ screen: r.key, type: 'theme_class_missing', detail: 'cc-admin-theme absent' });
  if (!r.analysis.cssLinks.some((h) => h.includes('/assets/css/admin-theme/index.css'))) {
    issues.push({ screen: r.key, type: 'css_entry_missing', detail: 'admin-theme/index.css not loaded' });
  }
  if (r.consoleErrors.length) issues.push({ screen: r.key, type: 'console_errors', detail: r.consoleErrors.slice(0, 2).join(' | ') });
  if (r.jsErrors.length) issues.push({ screen: r.key, type: 'page_errors', detail: r.jsErrors.slice(0, 2).join(' | ') });
  if (r.analysis.viewportOverflowX > 4 && (r.analysis.overflowsUnexpected || []).length > 0) {
    issues.push({ screen: r.key, type: 'viewport_overflow', detail: `horizontal overflow +${r.analysis.viewportOverflowX}px` });
  }
}

const markdown = buildMarkdownReport(all, {
  coreCount: coreScreens.length,
  pluginCount: pluginScreens.length + discoveredContentCoreScreens.length,
  thirdPartyCount: thirdPartyScreens.length
});

await fs.writeFile(MARKDOWN_REPORT, markdown, 'utf8');

console.log(`Markdown report: ${MARKDOWN_REPORT}`);
console.log(`Screenshots: ${SHOTS}`);
console.log(`Screens tested: ${all.length}`);
console.log(`Technical issues: ${issues.length}`);

await browser.close();
