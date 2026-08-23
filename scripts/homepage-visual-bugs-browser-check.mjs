import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
// Use an isolated debugging port so an interrupted prior check cannot capture
// this run's DevTools connection.
const port = 9300 + Math.floor(Math.random() * 500);
const profile = await mkdtemp(join(tmpdir(), 'reg-homepage-visual-bugs-'));
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const screenshots = {};
let chromeErrors = '';
const chrome = spawn(chromePath, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-gpu-sandbox',
  '--disable-extensions', '--disable-background-networking',
  '--ignore-certificate-errors', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--window-size=1440,1000', 'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
chrome.stderr.on('data', (chunk) => {
  chromeErrors += chunk.toString();
});

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
  socket.addEventListener('close', () => {
    for (const handlers of pending.values()) {
      handlers.reject(new Error(`Chrome DevTools connection closed before the browser check completed. ${chromeErrors.trim()}`));
    }
    pending.clear();
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
  const navigate = async (path, width, height) => {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: `https://reg-website.ddev.site${path}` });
    await ready();
  };
  const capture = async (label) => {
    const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false, fromSurface: true });
    const path = join(tmpdir(), `reg-homepage-visual-bugs-${label}-${Date.now()}.png`);
    await writeFile(path, Buffer.from(result.data, 'base64'));
    screenshots[label] = path;
  };

  await send('Page.enable');
  await send('Runtime.enable');

  const logoRoutes = ['/', '/home', '/sports', '/sports/results', '/sports/basketball-men'];
  const logoResults = [];
  for (const path of logoRoutes) {
    await navigate(path, 1440, 900);
    logoResults.push(await evaluate(`(() => {
      const logo = document.querySelector('.site-branding__logo');
      const link = document.querySelector('.site-branding__link');
      return {
        path: location.pathname,
        title: document.title,
        logoSrc: logo ? new URL(logo.currentSrc || logo.src).pathname : '',
        logoLoaded: Boolean(logo && logo.complete && logo.naturalWidth > 0 && logo.naturalHeight > 0),
        logoNaturalSize: logo ? [logo.naturalWidth, logo.naturalHeight] : [0, 0],
        logoRenderedSize: logo ? [Math.round(logo.getBoundingClientRect().width), Math.round(logo.getBoundingClientRect().height)] : [0, 0],
        logoHomeLink: Boolean(link) && new URL(link.href).pathname === '/',
        noBrokenBrandText: !document.querySelector('.site-branding__name'),
      };
    })()`));
  }

  const cardResults = [];
  for (const viewport of [{ label: 'desktop', width: 1440, height: 1000 }, { label: 'mobile', width: 390, height: 844 }]) {
    await navigate('/', viewport.width, viewport.height);
    const metrics = await evaluate(`(() => {
      const card = document.querySelector('.sports-card--result');
      const image = card?.querySelector('.sports-result-card__image');
      const overlay = card?.querySelector('.sports-result-card__overlay');
      const clone = card?.cloneNode(true);
      let fallbackBackground = '';
      if (clone) {
        clone.classList.remove('sports-card--image');
        clone.querySelector('.sports-card__image')?.remove();
        clone.querySelector('.sports-card__overlay')?.remove();
        clone.style.position = 'fixed';
        clone.style.left = '-10000px';
        document.body.appendChild(clone);
        fallbackBackground = getComputedStyle(clone, '::before').backgroundImage;
        clone.remove();
      }
      const rect = card?.getBoundingClientRect();
      const imageRect = image?.getBoundingClientRect();
      return {
        width: innerWidth,
        contentNoOverflow: Array.from(document.querySelectorAll('main *')).every((element) => {
          const bounds = element.getBoundingClientRect();
          return bounds.width <= 0 || (bounds.left >= -1 && bounds.right <= innerWidth + 1);
        }),
        cardFound: Boolean(card),
        imageLoaded: Boolean(image && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0),
        imageSrc: image ? new URL(image.currentSrc || image.src).pathname : '',
        imageAlt: image?.alt || '',
        // The image fills the card's padding box; allow for the one-pixel card
        // border on each edge when comparing the rendered rectangles.
        imageCoversCard: Boolean(rect && imageRect) && Math.abs(rect.width - imageRect.width) <= 2 && Math.abs(rect.height - imageRect.height) <= 2,
        objectFit: image ? getComputedStyle(image).objectFit : '',
        objectPosition: image ? getComputedStyle(image).objectPosition : '',
        overlayVisible: Boolean(overlay) && getComputedStyle(overlay).backgroundImage.includes('linear-gradient'),
        kicker: card?.querySelector('.sports-card__kicker')?.textContent.trim() || '',
        matchup: card?.querySelector('h3')?.textContent.trim() || '',
        status: card?.querySelector('.sports-card__status')?.textContent.trim() || '',
        score: card?.querySelector('small')?.textContent.trim() || '',
        viewResult: card?.querySelector('.sports-card__link')?.textContent.trim() || '',
        detailPath: card ? new URL(card.href).pathname : '',
        fallbackGradient: fallbackBackground !== '' && fallbackBackground !== 'none',
      };
    })()`);
    await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const card = document.querySelector('.sports-card--result'); window.scrollTo(0, window.scrollY + card.getBoundingClientRect().top - 120); })()`);
    await pause(400);
    await capture(`${viewport.label}-sports-result`);
    cardResults.push(metrics);
  }

  const logoPassed = logoResults.every((result) =>
    result.logoSrc === '/themes/custom/reg_theme/assets/images/reg-logo.svg'
    && result.logoLoaded
    && result.logoNaturalSize[0] > result.logoNaturalSize[1]
    && result.logoRenderedSize[0] === 104 && result.logoRenderedSize[1] === 48
    && result.logoHomeLink && result.noBrokenBrandText
  );
  const cardsPassed = cardResults.every((result) =>
    result.contentNoOverflow && result.cardFound && result.imageLoaded
    && result.imageSrc === '/sites/default/files/sports/reg-vs-patriots-74-71.jpg'
    && result.imageAlt === 'REG basketball player during the REG 74–71 Patriots match'
    && result.imageCoversCard && result.objectFit === 'cover' && result.objectPosition === '50% 35%'
    && result.overlayVisible && result.kicker === 'Latest result' && result.matchup === 'REG vs Patriots'
    && result.status === 'Full Time' && result.score === 'REG 74 – 71 Patriots'
    && result.viewResult.startsWith('View result') && result.detailPath === '/sports/match/50'
    && result.fallbackGradient
  );
  const passed = logoPassed && cardsPassed;
  console.log(JSON.stringify({ passed, logoPassed, cardsPassed, logoResults, cardResults, screenshots }, null, 2));
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
