import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9337;
const profile = await mkdtemp(join(tmpdir(), 'reg-navigation-'));
const stamp = Date.now();
const desktopScreenshot = join(tmpdir(), `reg-navigation-desktop-${stamp}.png`);
const mobileScreenshot = join(tmpdir(), `reg-navigation-mobile-${stamp}.png`);
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

const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
let socket;

try {
  let targets;
  for (let attempt = 0; attempt < 80; attempt += 1) {
    try {
      targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      if (targets.some((target) => target.type === 'page' && !target.url.startsWith('chrome-extension:'))) break;
    }
    catch {
      // Chrome is still starting.
    }
    await pause(100);
  }
  const pageTarget = targets?.find((target) => target.type === 'page' && !target.url.startsWith('chrome-extension:'));
  if (!pageTarget?.webSocketDebuggerUrl) throw new Error('Chrome DevTools endpoint did not start.');

  socket = new WebSocket(pageTarget.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  let commandId = 0;
  const pending = new Map();
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (!message.id || !pending.has(message.id)) return;
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(message.error.message));
    else resolve(message.result);
  });

  const send = (method, params = {}) => new Promise((resolve, reject) => {
    commandId += 1;
    pending.set(commandId, { resolve, reject });
    socket.send(JSON.stringify({ id: commandId, method, params }));
  });
  const evaluate = async (expression) => {
    const response = await send('Runtime.evaluate', {
      expression,
      awaitPromise: true,
      returnByValue: true,
    });
    if (response.exceptionDetails) {
      throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
    }
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
    await send('Emulation.setDeviceMetricsOverride', {
      width,
      height,
      deviceScaleFactor: 1,
      mobile,
    });
    await send('Page.navigate', { url: 'https://reg-website.ddev.site/outages' });
    await ready();
  };
  const screenshot = async (path) => {
    const capture = await send('Page.captureScreenshot', {
      format: 'png',
      captureBeyondViewport: false,
      fromSurface: true,
    });
    await writeFile(path, Buffer.from(capture.data, 'base64'));
  };

  await send('Page.enable');
  await send('Runtime.enable');
  await navigate(1440, 1000);

  const desktopInitial = await evaluate(`(() => {
    const header = document.querySelector('[data-reg-header]');
    const utility = document.querySelector('.topbar');
    const menuToggle = document.querySelector('[data-reg-menu-toggle]');
    const customer = document.querySelector('.primary-menu > .menu-item--mobile-priority-1');
    if (!header || !utility || !menuToggle || !customer) {
      return { loadError: true, url: location.href, title: document.title, body: document.body.innerText.slice(0, 300) };
    }
    const rect = customer.getBoundingClientRect();
    return {
      width: innerWidth,
      documentWidth: document.documentElement.clientWidth,
      headerWidth: Math.round(header.getBoundingClientRect().width),
      utilityVisible: getComputedStyle(utility).display !== 'none',
      mobileToggleHidden: getComputedStyle(menuToggle).display === 'none',
      customerActive: customer.classList.contains('menu-item--active-trail'),
      customerRect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      emptyLinks: Array.from(header.querySelectorAll('a')).filter((link) => !link.getAttribute('href') || link.getAttribute('href') === '#' || link.href.startsWith('javascript:')).length,
      invalidControls: Array.from(header.querySelectorAll('[aria-controls]')).filter((control) => !document.getElementById(control.getAttribute('aria-controls'))).length,
    };
  })()`);
  if (desktopInitial.loadError) throw new Error(JSON.stringify(desktopInitial));

  await send('Input.dispatchMouseEvent', {
    type: 'mouseMoved',
    x: desktopInitial.customerRect.x + desktopInitial.customerRect.width / 2,
    y: desktopInitial.customerRect.y + desktopInitial.customerRect.height / 2,
  });
  await pause(150);
  const hoverOpen = await evaluate(`(() => {
    const item = document.querySelector('.primary-menu > .menu-item--mobile-priority-1');
    return item.classList.contains('is-submenu-open')
      && item.querySelector('[data-reg-submenu-toggle]').getAttribute('aria-expanded') === 'true'
      && getComputedStyle(item.querySelector(':scope > .submenu-wrap')).display !== 'none';
  })()`);

  await evaluate(`document.querySelector('.primary-menu > .menu-item--mobile-priority-1 > .menu-item__row > a').focus()`);
  await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape' });
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
  await pause(100);
  const keyboardEscapeClosed = await evaluate(`(() => {
    const item = document.querySelector('.primary-menu > .menu-item--mobile-priority-1');
    return !item.classList.contains('is-submenu-open')
      && item.querySelector('[data-reg-submenu-toggle]').getAttribute('aria-expanded') === 'false';
  })()`);

  await evaluate(`document.querySelector('.primary-menu > .menu-item--mobile-priority-1 [data-reg-submenu-toggle]').click()`);
  await pause(100);
  const clickOpen = await evaluate(`document.querySelector('.primary-menu > .menu-item--mobile-priority-1').classList.contains('is-submenu-open')`);
  await screenshot(desktopScreenshot);

  await navigate(390, 844, true);
  const mobileClosed = await evaluate(`(() => ({
    toggleVisible: getComputedStyle(document.querySelector('[data-reg-menu-toggle]')).display !== 'none',
    panelClosed: !document.querySelector('[data-reg-menu]').classList.contains('is-open'),
  }))()`);
  await evaluate(`document.querySelector('[data-reg-menu-toggle]').click()`);
  await pause(120);
  await evaluate(`document.querySelector('.primary-menu > .menu-item--mobile-priority-1 [data-reg-submenu-toggle]').click()`);
  await pause(120);
  await evaluate(`document.querySelector('[data-reg-menu]').scrollTop = 0; window.scrollTo(0, 0)`);
  const mobileOpen = await evaluate(`(() => {
    const panel = document.querySelector('[data-reg-menu]');
    const panelRect = panel.getBoundingClientRect();
    const customer = document.querySelector('.primary-menu > .menu-item--mobile-priority-1');
    const visible = (selector) => {
      const element = document.querySelector(selector);
      const style = element ? getComputedStyle(element) : null;
      return element && style.display !== 'none' && style.visibility !== 'hidden' && element.getBoundingClientRect().height > 0;
    };
    const ordered = Array.from(document.querySelectorAll('.primary-menu > .menu-item'))
      .map((item) => ({ title: item.querySelector(':scope > .menu-item__row > a, :scope > .menu-item__row > span')?.textContent.trim(), y: item.getBoundingClientRect().y }))
      .sort((a, b) => a.y - b.y);
    const targets = Array.from(panel.querySelectorAll('a, button')).filter((element) => element.getBoundingClientRect().height > 0);
    return {
      panelOpen: panel.classList.contains('is-open'),
      panelInViewport: panelRect.left >= 0 && panelRect.right <= innerWidth + 1,
      customerFirst: ordered[0]?.title === 'Customer Services',
      customerAccordionOpen: customer.classList.contains('is-submenu-open') && customer.querySelector('[data-reg-submenu-toggle]').getAttribute('aria-expanded') === 'true',
      searchVisible: visible('.header-search'),
      languagesVisible: visible('.header-language'),
      callVisible: visible('.mobile-nav__call'),
      utilityVisible: visible('.mobile-nav__utility'),
      assistantHidden: !visible('.reg-assistant'),
      minimumTargetHeight: Math.round(Math.min(...targets.map((element) => element.getBoundingClientRect().height))),
    };
  })()`);
  await screenshot(mobileScreenshot);

  await evaluate(`document.querySelector('.primary-menu > .menu-item--mobile-priority-1 > .menu-item__row > a').focus()`);
  await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape' });
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
  await pause(80);
  await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape' });
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
  await pause(80);
  const mobileEscapeClosed = await evaluate(`!document.querySelector('[data-reg-menu]').classList.contains('is-open') && document.querySelector('[data-reg-menu-toggle]').getAttribute('aria-expanded') === 'false'`);

  const checks = {
    desktopInitial,
    hoverOpen,
    keyboardEscapeClosed,
    clickOpen,
    mobileClosed,
    mobileOpen,
    mobileEscapeClosed,
    screenshots: { desktopScreenshot, mobileScreenshot },
  };
  const passed = desktopInitial.width === 1440
    && desktopInitial.headerWidth === desktopInitial.documentWidth
    && desktopInitial.utilityVisible
    && desktopInitial.mobileToggleHidden
    && desktopInitial.customerActive
    && desktopInitial.emptyLinks === 0
    && desktopInitial.invalidControls === 0
    && hoverOpen
    && keyboardEscapeClosed
    && clickOpen
    && mobileClosed.toggleVisible
    && mobileClosed.panelClosed
    && mobileOpen.panelOpen
    && mobileOpen.panelInViewport
    && mobileOpen.customerFirst
    && mobileOpen.customerAccordionOpen
    && mobileOpen.searchVisible
    && mobileOpen.languagesVisible
    && mobileOpen.callVisible
    && mobileOpen.utilityVisible
    && mobileOpen.assistantHidden
    && mobileOpen.minimumTargetHeight >= 38
    && mobileEscapeClosed;
  console.log(JSON.stringify({ passed, ...checks }, null, 2));
  if (!passed) process.exitCode = 1;
}
finally {
  socket?.close();
  if (chrome.exitCode === null) {
    const exited = new Promise((resolve) => chrome.once('exit', resolve));
    chrome.kill();
    await Promise.race([exited, pause(2000)]);
  }
  for (let attempt = 0; attempt < 10; attempt += 1) {
    try {
      await rm(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 });
      break;
    }
    catch (error) {
      if (attempt === 9) throw error;
      await pause(150);
    }
  }
}
