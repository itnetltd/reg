import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9338;
const profile = await mkdtemp(join(tmpdir(), 'reg-sports-'));
const stamp = Date.now();
const desktopScreenshot = join(tmpdir(), `reg-sports-desktop-${stamp}.png`);
const mobileScreenshot = join(tmpdir(), `reg-sports-mobile-${stamp}.png`);
const resultsScreenshot = join(tmpdir(), `reg-sports-results-${stamp}.png`);
const resultsMobileScreenshot = join(tmpdir(), `reg-sports-results-mobile-${stamp}.png`);
const chrome = spawn(chromePath, [
  '--headless=new',
  '--disable-gpu',
  '--disable-extensions',
  '--disable-background-networking',
  '--ignore-certificate-errors',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`,
  '--window-size=1440,1100',
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
        await pause(450);
        return;
      }
      await pause(100);
    }
    throw new Error('Page did not finish loading.');
  };
  const navigate = async (url, width, height, mobile = false) => {
    await send('Emulation.setDeviceMetricsOverride', {
      width,
      height,
      deviceScaleFactor: 1,
      mobile,
    });
    await send('Page.navigate', { url });
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
  await navigate('https://reg-website.ddev.site/sports', 1440, 1100);

  const desktop = await evaluate(`(() => {
    const hero = document.querySelector('.reg-sports-hero');
    const image = document.querySelector('.reg-sports-hero__image');
    const panel = document.querySelector('.reg-sports-hero-result');
    const cta = document.querySelector('.reg-sports-hero-result__cta');
    const headings = Array.from(document.querySelectorAll('.reg-sports__body > section h2')).map((heading) => heading.textContent.trim());
    cta?.focus();
    const focusStyle = cta ? getComputedStyle(cta) : null;
    return {
      width: innerWidth,
      noHorizontalOverflow: document.documentElement.scrollWidth === document.documentElement.clientWidth,
      heroHeight: Math.round(hero?.getBoundingClientRect().height || 0),
      imageLoaded: Boolean(image?.complete && image.naturalWidth > 0 && image.naturalHeight > 0),
      naturalSize: image ? [image.naturalWidth, image.naturalHeight] : [],
      imageObjectFit: image ? getComputedStyle(image).objectFit : '',
      imageAlt: image?.getAttribute('alt') || '',
      foregroundImageCount: document.querySelectorAll('.reg-sports-hero__image[alt="REG basketball player during the REG 74–71 Patriots match"]').length,
      panelWidth: Math.round(panel?.getBoundingClientRect().width || 0),
      resultText: panel?.innerText || '',
      scoreAria: panel?.getAttribute('aria-label') || '',
      ctaHref: cta?.getAttribute('href') || '',
      ctaFocused: document.activeElement === cta,
      ctaOutlineWidth: focusStyle?.outlineWidth || '',
      sectionHeadings: headings,
    };
  })()`);
  await screenshot(desktopScreenshot);

  await navigate('https://reg-website.ddev.site/sports', 390, 900, true);
  const mobile = await evaluate(`(() => {
    const hero = document.querySelector('.reg-sports-hero');
    const media = document.querySelector('.reg-sports-hero__media');
    const image = document.querySelector('.reg-sports-hero__image');
    const panel = document.querySelector('.reg-sports-hero-result');
    const cta = document.querySelector('.reg-sports-hero-result__cta');
    const panelRect = panel?.getBoundingClientRect();
    return {
      width: innerWidth,
      noHorizontalOverflow: document.documentElement.scrollWidth === document.documentElement.clientWidth,
      heroHeight: Math.round(hero?.getBoundingClientRect().height || 0),
      mediaHeight: Math.round(media?.getBoundingClientRect().height || 0),
      imageObjectFit: image ? getComputedStyle(image).objectFit : '',
      imageLoaded: Boolean(image?.complete && image.naturalWidth > 0),
      panelInViewportWidth: Boolean(panelRect && panelRect.left >= 0 && panelRect.right <= innerWidth),
      resultText: panel?.innerText || '',
      homeScoreSize: getComputedStyle(document.querySelector('.reg-sports-hero-result__score strong')).fontSize,
      ctaMinimumHeight: Math.round(cta?.getBoundingClientRect().height || 0),
    };
  })()`);
  await screenshot(mobileScreenshot);

  await navigate('https://reg-website.ddev.site/sports/results', 1440, 1000);
  const results = await evaluate(`(() => {
    const card = document.querySelector('.reg-sports-fixture-card');
    return {
      noHorizontalOverflow: document.documentElement.scrollWidth === document.documentElement.clientWidth,
      cardText: card?.innerText || '',
      cardHref: card?.querySelector('h2 a')?.getAttribute('href') || '',
      emptyDefinitionRows: Array.from(card?.querySelectorAll('dl div') || []).filter((row) => !row.querySelector('dd')?.textContent.trim()).length,
    };
  })()`);
  await screenshot(resultsScreenshot);

  await navigate('https://reg-website.ddev.site/sports/results', 390, 900, true);
  const resultsMobile = await evaluate(`(() => {
    const card = document.querySelector('.reg-sports-fixture-card');
    const filter = document.querySelector('.reg-sports-filter');
    const cardRect = card?.getBoundingClientRect();
    return {
      noHorizontalOverflow: document.documentElement.scrollWidth === document.documentElement.clientWidth,
      filterColumns: filter ? getComputedStyle(filter).gridTemplateColumns.split(' ').length : 0,
      cardInViewportWidth: Boolean(cardRect && cardRect.left >= 0 && cardRect.right <= innerWidth),
      cardText: card?.innerText || '',
      linkMinimumHeight: Math.round(card?.querySelector(':scope > a')?.getBoundingClientRect().height || 0),
    };
  })()`);
  await screenshot(resultsMobileScreenshot);

  const expectedSections = ['Fixtures and results', 'REG teams', 'Standings', 'Sports news', 'Featured players', 'Latest galleries', 'Video highlights'];
  const passed = desktop.width === 1440
    && desktop.noHorizontalOverflow
    && desktop.heroHeight >= 650
    && desktop.imageLoaded
    && desktop.naturalSize[0] === 399
    && desktop.naturalSize[1] === 501
    && desktop.imageObjectFit === 'contain'
    && desktop.imageAlt === 'REG basketball player during the REG 74–71 Patriots match'
    && desktop.foregroundImageCount === 1
    && desktop.resultText.includes('FULL TIME')
    && desktop.resultText.includes('REG')
    && desktop.resultText.includes('74')
    && desktop.resultText.includes('PATRIOTS')
    && desktop.resultText.includes('71')
    && desktop.scoreAria.includes('REG 74 – 71 Patriots')
    && desktop.ctaHref === '/sports/match/50'
    && desktop.ctaFocused
    && desktop.ctaOutlineWidth !== '0px'
    && expectedSections.every((heading, index) => desktop.sectionHeadings[index] === heading)
    && mobile.width === 390
    && mobile.noHorizontalOverflow
    && mobile.heroHeight >= 880
    && mobile.mediaHeight >= 430
    && mobile.imageObjectFit === 'contain'
    && mobile.imageLoaded
    && mobile.panelInViewportWidth
    && mobile.resultText.includes('FULL TIME')
    && Number.parseFloat(mobile.homeScoreSize) >= 57
    && mobile.ctaMinimumHeight >= 48
    && results.noHorizontalOverflow
    && results.cardText.includes('FULL TIME')
    && results.cardText.includes('REG 74 – 71 Patriots')
    && results.cardHref === '/sports/match/50'
    && results.emptyDefinitionRows === 0
    && resultsMobile.noHorizontalOverflow
    && resultsMobile.filterColumns === 1
    && resultsMobile.cardInViewportWidth
    && resultsMobile.cardText.includes('FULL TIME')
    && resultsMobile.cardText.includes('REG 74 – 71 Patriots')
    && resultsMobile.linkMinimumHeight >= 44;

  console.log(JSON.stringify({
    passed,
    desktop,
    mobile,
    results,
    resultsMobile,
    screenshots: { desktopScreenshot, mobileScreenshot, resultsScreenshot, resultsMobileScreenshot },
  }, null, 2));
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
