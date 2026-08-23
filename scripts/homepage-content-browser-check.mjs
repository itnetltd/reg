import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9343;
const profile = await mkdtemp(join(tmpdir(), 'reg-homepage-content-'));
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const screenshots = {};
const chrome = spawn(chromePath, [
  '--headless=new', '--disable-gpu', '--disable-extensions', '--disable-background-networking',
  '--ignore-certificate-errors', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--window-size=1440,1000', 'about:blank',
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
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
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
  const capture = async (label) => {
    const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false, fromSurface: true });
    const path = join(tmpdir(), `reg-homepage-content-${label}-${Date.now()}.png`);
    await writeFile(path, Buffer.from(result.data, 'base64'));
    screenshots[label] = path;
  };

  await send('Page.enable');
  await send('Runtime.enable');

  const viewports = [
    { label: 'desktop', width: 1440, height: 1000 },
    { label: 'mobile', width: 390, height: 844 },
  ];
  const results = [];
  for (const viewport of viewports) {
    await send('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: 'https://reg-website.ddev.site/' });
    await ready();
    const metrics = await evaluate(`(() => {
      const hero = document.querySelector('.hero');
      const media = hero.querySelector('.hero__media');
      const overlay = hero.querySelector('.hero__overlay');
      const title = hero.querySelector('h1');
      const summary = hero.querySelector('.hero__summary');
      const actions = Array.from(hero.querySelectorAll('.hero__actions a'));
      const partners = Array.from(document.querySelectorAll('.partner-grid .partner-card'));
      const partnerLinks = partners.filter((item) => item.matches('a')).map((item) => ({ target: item.target, rel: item.rel }));
      const partnerGrid = document.querySelector('.partner-grid');
      const heroRect = hero.getBoundingClientRect();
      const mediaRect = media.getBoundingClientRect();
      const overflowElements = Array.from(document.querySelectorAll('body *')).filter((element) => {
        const rect = element.getBoundingClientRect();
        return rect.width > 0 && (rect.left < -1 || rect.right > innerWidth + 1);
      }).slice(0, 12).map((element) => ({ tag: element.tagName, className: element.className, left: Math.round(element.getBoundingClientRect().left), right: Math.round(element.getBoundingClientRect().right) }));
      const contentNoOverflow = Array.from(document.querySelectorAll('main *')).every((element) => {
        const rect = element.getBoundingClientRect();
        return rect.width <= 0 || (rect.left >= -1 && rect.right <= innerWidth + 1);
      });
      return {
        width: innerWidth,
        noOverflow: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
        contentNoOverflow,
        heroTitle: title?.textContent.trim(),
        heroSummary: summary?.textContent.trim(),
        heroActions: actions.map((link) => ({ label: link.textContent.trim(), path: new URL(link.href).pathname })),
        heroHeight: Math.round(heroRect.height),
        mediaCoversHero: Math.abs(heroRect.width - mediaRect.width) <= 1 && Math.abs(heroRect.height - mediaRect.height) <= 1,
        overlayPresent: Boolean(overlay) && getComputedStyle(overlay).backgroundImage !== 'none',
        titleReadable: getComputedStyle(title).color === 'rgb(255, 255, 255)' && parseFloat(getComputedStyle(title).fontSize) >= (innerWidth < 761 ? 45 : 55),
        cmsBlocks: Boolean(document.getElementById('block-reg-homepage-hero') && document.getElementById('block-reg-homepage-partners')),
        partnerNames: partners.map((item) => item.textContent.trim()),
        partnerLinks,
        safeExternalPartners: partnerLinks.every((link) => link.target === '_blank' && ['noopener', 'noreferrer', 'external'].every((token) => link.rel.split(' ').includes(token))),
        partnerColumns: partnerGrid ? getComputedStyle(partnerGrid).gridTemplateColumns.split(' ').filter((column) => column !== '0px').length : 0,
        partnersInViewport: partners.every((item) => item.getBoundingClientRect().width > 0),
        preservedSections: ['.service-alert', '.section--services', '.impact-section', '.section--status'].every((selector) => document.querySelector(selector)),
        overflowElements,
      };
    })()`);
    await capture(`${viewport.label}-hero`);
    await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const section = document.querySelector('.partners-section'); window.scrollTo(0, window.scrollY + section.getBoundingClientRect().top); })()`);
    await pause(500);
    await capture(`${viewport.label}-partners`);
    results.push(metrics);
  }

  const expectedPartners = ['MININFRA', 'RURA', 'EUCL', 'EDCL'];
  const passed = results.every((result) =>
    result.contentNoOverflow
    && result.heroTitle === 'Energy with a vision for the future'
    && result.heroSummary === 'Driving Rwanda’s progress through sustainable and reliable power solutions.'
    && JSON.stringify(result.heroActions) === JSON.stringify([
      { label: 'Check outages', path: '/outages' },
      { label: 'Online services', path: '/online-services' },
    ])
    && result.heroHeight >= (result.width < 761 ? 620 : 690)
    && result.mediaCoversHero && result.overlayPresent && result.titleReadable && result.cmsBlocks
    && expectedPartners.every((name) => result.partnerNames.includes(name))
    && result.partnerColumns === (result.width < 761 ? 2 : result.partnerNames.length)
    && result.safeExternalPartners
    && result.partnersInViewport && result.preservedSections
  );
  console.log(JSON.stringify({ passed, results, screenshots }, null, 2));
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
