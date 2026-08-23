import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9341;
const profile = await mkdtemp(join(tmpdir(), 'reg-header-'));
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const screenshots = {};
const chrome = spawn(chromePath, [
  '--headless=new',
  '--disable-gpu',
  '--disable-extensions',
  '--disable-background-networking',
  '--ignore-certificate-errors',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`,
  '--window-size=1440,1000',
  'about:blank',
], { stdio: 'ignore', windowsHide: true });

let socket;
try {
  let targets;
  for (let attempt = 0; attempt < 80; attempt += 1) {
    try {
      targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      if (targets.some((target) => target.type === 'page')) break;
    }
    catch {
      // Chrome is still starting.
    }
    await pause(100);
  }
  const target = targets?.find((item) => item.type === 'page');
  if (!target?.webSocketDebuggerUrl) throw new Error('Chrome DevTools endpoint did not start.');

  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  let commandId = 0;
  const pending = new Map();
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (!message.id || !pending.has(message.id)) return;
    const handlers = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) handlers.reject(new Error(message.error.message));
    else handlers.resolve(message.result);
  });
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    commandId += 1;
    pending.set(commandId, { resolve, reject });
    socket.send(JSON.stringify({ id: commandId, method, params }));
  });
  const evaluate = async (expression) => {
    const response = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
    return response.result.value;
  };
  const ready = async () => {
    for (let attempt = 0; attempt < 100; attempt += 1) {
      if (await evaluate('document.readyState === "complete"')) {
        await pause(350);
        return;
      }
      await pause(100);
    }
    throw new Error('Page did not finish loading.');
  };
  const navigate = async (width, height, mobile = false) => {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    await send('Page.navigate', { url: 'https://reg-website.ddev.site/' });
    await ready();
  };
  const key = async (value) => {
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: value, code: value });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: value, code: value });
    await pause(80);
  };
  const capture = async (label) => {
    const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false, fromSurface: true });
    const path = join(tmpdir(), `reg-header-${label}-${Date.now()}.png`);
    await writeFile(path, Buffer.from(result.data, 'base64'));
    screenshots[label] = path;
  };

  await send('Page.enable');
  await send('Runtime.enable');

  const desktopResults = [];
  for (const width of [1440, 1280]) {
    await navigate(width, 900);
    const metrics = await evaluate(`(() => {
      const visible = (element) => element && getComputedStyle(element).display !== 'none' && getComputedStyle(element).visibility !== 'hidden' && element.getBoundingClientRect().height > 0;
      const primaryLinks = Array.from(document.querySelectorAll('.primary-menu > .menu-item > .menu-item__row > a, .primary-menu > .menu-item > .menu-item__row > span'));
      const utilityLinks = Array.from(document.querySelectorAll('.topbar .utility-menu > li > a'));
      const topbar = document.querySelector('.topbar');
      const logo = document.querySelector('.site-branding__link');
      const activeLanguages = Array.from(document.querySelectorAll('.topbar .language-switcher a.is-active'));
      return {
        width: innerWidth,
        noOverflow: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
        documentWidth: document.documentElement.clientWidth,
        headerWidth: Math.round(document.querySelector('[data-reg-header]').getBoundingClientRect().width),
        utilityVisible: visible(topbar),
        utilityHeight: Math.round(topbar.getBoundingClientRect().height),
        toggleHidden: !visible(document.querySelector('[data-reg-menu-toggle]')),
        primaryTitles: primaryLinks.map((link) => link.textContent.trim()),
        utilityTitles: utilityLinks.map((link) => link.textContent.trim()),
        labelsNoWrap: primaryLinks.every((link) => getComputedStyle(link).whiteSpace === 'nowrap' && link.getClientRects().length === 1),
        searchInUtility: visible(document.querySelector('.topbar [data-reg-header-search]')),
        searchAbsentFromMainbar: !visible(document.querySelector('.mainbar [data-reg-header-search]')),
        languageInUtility: visible(document.querySelector('.topbar .language-switcher')),
        languageAbsentFromMainbar: !document.querySelector('.mainbar > .header-language'),
        languageLabels: Array.from(document.querySelectorAll('.topbar .language-switcher__short')).map((label) => label.textContent.trim()),
        oneActiveLanguage: activeLanguages.length === 1 && getComputedStyle(activeLanguages[0]).backgroundColor === 'rgb(218, 41, 28)',
        logoHome: new URL(logo.href).pathname === '/',
        logoSize: { width: Math.round(logo.getBoundingClientRect().width), height: Math.round(logo.getBoundingClientRect().height) },
        validControls: Array.from(document.querySelectorAll('[aria-controls]')).every((control) => document.getElementById(control.getAttribute('aria-controls'))),
        preserved: Boolean(document.querySelector('.service-alert') && document.querySelector('.hero') && document.querySelector('.reg-assistant')),
      };
    })()`);

    const searchInteraction = await evaluate(`(() => {
      const toggle = document.querySelector('.topbar [data-reg-search-toggle]');
      toggle.click();
      const panel = document.getElementById(toggle.getAttribute('aria-controls'));
      return { expanded: toggle.getAttribute('aria-expanded'), panelVisible: !panel.hidden, inputFocused: document.activeElement === panel.querySelector('input[type="search"]') };
    })()`);
    await pause(80);
    searchInteraction.inputFocused = await evaluate(`document.activeElement === document.querySelector('.topbar [data-reg-search-panel] input[type="search"]')`);
    await key('Escape');
    searchInteraction.escapeClosed = await evaluate(`(() => {
      const toggle = document.querySelector('.topbar [data-reg-search-toggle]');
      const panel = document.getElementById(toggle.getAttribute('aria-controls'));
      return toggle.getAttribute('aria-expanded') === 'false' && panel.hidden && document.activeElement === toggle;
    })()`);
    desktopResults.push({ ...metrics, searchInteraction });
    await capture(String(width));
  }

  await navigate(1440, 900);
  const keyboardMenu = await evaluate(`(() => {
    const toggle = document.querySelector('.primary-menu > .menu-item [data-reg-submenu-toggle]');
    toggle.focus();
    return Boolean(toggle);
  })()`);
  if (!keyboardMenu) throw new Error('No primary submenu toggle was rendered.');
  await key('ArrowDown');
  const arrowOpened = await evaluate(`document.querySelector('.primary-menu > .menu-item [data-reg-submenu-toggle]').getAttribute('aria-expanded') === 'true'`);
  await key('Escape');
  const escapeClosedSubmenu = await evaluate(`document.querySelector('.primary-menu > .menu-item [data-reg-submenu-toggle]').getAttribute('aria-expanded') === 'false'`);

  const mobileResults = [];
  for (const viewport of [{ width: 1024, height: 900, mobile: false }, { width: 390, height: 844, mobile: false }]) {
    await navigate(viewport.width, viewport.height, viewport.mobile);
    await evaluate(`document.querySelector('[data-reg-menu-toggle]').click()`);
    await pause(300);
    const metrics = await evaluate(`(() => {
      const visible = (element) => element && getComputedStyle(element).display !== 'none' && getComputedStyle(element).visibility !== 'hidden' && element.getBoundingClientRect().height > 0;
      const panel = document.querySelector('[data-reg-menu]');
      const primary = Array.from(panel.querySelectorAll('.primary-menu > .menu-item > .menu-item__row > a, .primary-menu > .menu-item > .menu-item__row > span'));
      const utility = Array.from(panel.querySelectorAll('.mobile-nav__utility .utility-menu a')).filter(visible);
      const byPosition = (a, b) => a.getBoundingClientRect().y - b.getBoundingClientRect().y || a.getBoundingClientRect().x - b.getBoundingClientRect().x;
      const customer = primary.find((link) => link.textContent.trim() === 'Customer Services');
      const about = primary.find((link) => link.textContent.trim() === 'About REG');
      const outages = utility.find((link) => link.textContent.trim() === 'Power Outages');
      const online = utility.find((link) => link.textContent.trim() === 'Online Services');
      const targets = Array.from(panel.querySelectorAll('a, button')).filter(visible);
      const panelRect = panel.getBoundingClientRect();
      const headerElements = Array.from(document.querySelectorAll('[data-reg-header], [data-reg-header] *')).filter(visible);
      return {
        width: innerWidth,
        topbarHidden: !visible(document.querySelector('.topbar')),
        toggleVisible: visible(document.querySelector('[data-reg-menu-toggle]')),
        panelOpen: panel.classList.contains('is-open'),
        panelInViewport: panelRect.left >= 0 && panelRect.right <= innerWidth + 1,
        noOverflow: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
        headerNoOverflow: headerElements.every((element) => {
          const rect = element.getBoundingClientRect();
          return rect.left >= -1 && rect.right <= innerWidth + 1;
        }),
        primaryTitles: primary.map((link) => link.textContent.trim()),
        utilityTitles: utility.sort(byPosition).map((link) => link.textContent.trim()),
        toolsVisible: visible(panel.querySelector('.mobile-nav__tools')),
        searchVisible: visible(panel.querySelector('.mobile-nav__tools [data-reg-search-toggle]')),
        languagesVisible: visible(panel.querySelector('.mobile-nav__language .language-switcher')),
        ordered: Boolean(customer && outages && online && about) && customer.getBoundingClientRect().y < outages.getBoundingClientRect().y && outages.getBoundingClientRect().y <= online.getBoundingClientRect().y && online.getBoundingClientRect().y < about.getBoundingClientRect().y,
        minimumTargetHeight: Math.round(Math.min(...targets.map((target) => target.getBoundingClientRect().height))),
        assistantHidden: !visible(document.querySelector('.reg-assistant')),
      };
    })()`);
    await evaluate(`document.querySelector('.mobile-nav__tools [data-reg-search-toggle]').click()`);
    await pause(80);
    metrics.searchExpanded = await evaluate(`(() => {
      const toggle = document.querySelector('.mobile-nav__tools [data-reg-search-toggle]');
      const panel = document.getElementById(toggle.getAttribute('aria-controls'));
      const rect = panel.getBoundingClientRect();
      return toggle.getAttribute('aria-expanded') === 'true' && !panel.hidden && document.activeElement === panel.querySelector('input[type="search"]') && rect.left >= 0 && rect.right <= innerWidth + 1;
    })()`);
    await capture(String(viewport.width));
    await key('Escape');
    metrics.searchEscapeClosed = await evaluate(`document.querySelector('.mobile-nav__tools [data-reg-search-toggle]').getAttribute('aria-expanded') === 'false'`);
    mobileResults.push(metrics);
  }

  const primaryExpected = ['About REG', 'What We Do', 'Customer Services', 'Public Information', 'Media Center', 'Sports', 'Contact'];
  const utilityExpected = ['Safety', 'Online Services', 'Power Outages', 'Tenders', 'Jobs', 'Contact'];
  const desktopPassed = desktopResults.every((result) =>
    result.documentWidth === result.headerWidth
    && result.noOverflow
    && result.utilityVisible
    && result.utilityHeight >= 38 && result.utilityHeight <= 42
    && result.toggleHidden
    && JSON.stringify(result.primaryTitles) === JSON.stringify(primaryExpected)
    && JSON.stringify(result.utilityTitles) === JSON.stringify(utilityExpected)
    && result.labelsNoWrap
    && result.searchInUtility && result.searchAbsentFromMainbar
    && result.languageInUtility && result.languageAbsentFromMainbar
    && result.languageLabels.join(' ') === 'EN RW'
    && result.oneActiveLanguage && result.logoHome
    && result.logoSize.width <= 104 && result.logoSize.height <= 48
    && result.validControls && result.preserved
    && result.searchInteraction.expanded === 'true'
    && result.searchInteraction.panelVisible
    && result.searchInteraction.inputFocused
    && result.searchInteraction.escapeClosed
  );
  const mobilePassed = mobileResults.every((result) =>
    result.topbarHidden && result.toggleVisible && result.panelOpen && result.panelInViewport && result.headerNoOverflow
    && JSON.stringify(result.primaryTitles) === JSON.stringify(primaryExpected)
    && JSON.stringify(result.utilityTitles) === JSON.stringify(['Power Outages', 'Online Services'])
    && result.toolsVisible && result.searchVisible && result.languagesVisible && result.ordered
    && result.minimumTargetHeight >= 38 && result.assistantHidden
    && result.searchExpanded && result.searchEscapeClosed
  );
  const passed = desktopPassed && mobilePassed && arrowOpened && escapeClosedSubmenu;
  console.log(JSON.stringify({ passed, desktopPassed, mobilePassed, arrowOpened, escapeClosedSubmenu, desktopResults, mobileResults, screenshots }, null, 2));
  if (!passed) process.exitCode = 1;
}
finally {
  socket?.close();
  if (chrome.exitCode === null) {
    const exited = new Promise((resolve) => chrome.once('exit', resolve));
    chrome.kill();
    await Promise.race([exited, pause(2000)]);
  }
  await rm(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 });
}
