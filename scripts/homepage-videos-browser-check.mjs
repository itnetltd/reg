import {spawn} from 'node:child_process';
import {mkdtemp, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9500 + Math.floor(Math.random() * 300);
const profile = await mkdtemp(join(tmpdir(), 'reg-featured-videos-'));
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const screenshots = {};
let chromeErrors = '';
const chrome = spawn(chromePath, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-gpu-sandbox',
  '--disable-extensions', '--disable-background-networking', '--ignore-certificate-errors',
  `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--window-size=1440,1000', 'about:blank'
], {stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true});
chrome.stderr.on('data', (chunk) => { chromeErrors += chunk.toString(); });

let socket;
try {
  let targets;
  for (let attempt = 0; attempt < 80; attempt += 1) {
    try {
      targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      if (targets.some((target) => target.type === 'page')) break;
    }
    catch {}
    await pause(100);
  }
  const target = targets?.find((item) => item.type === 'page');
  if (!target?.webSocketDebuggerUrl) throw new Error('Chrome DevTools endpoint did not start.');
  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, {once: true});
    socket.addEventListener('error', reject, {once: true});
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
    for (const handlers of pending.values()) handlers.reject(new Error(`Chrome DevTools closed. ${chromeErrors.trim()}`));
    pending.clear();
  });
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    commandId += 1;
    pending.set(commandId, {resolve, reject});
    socket.send(JSON.stringify({id: commandId, method, params}));
  });
  const evaluate = async (expression) => {
    const response = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
    if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
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
  const navigate = async (path, width, height) => {
    await send('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: false});
    await send('Page.navigate', {url: `https://reg-website.ddev.site${path}`});
    await ready();
  };
  const capture = async (label) => {
    const result = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false, fromSurface: true});
    const path = join(tmpdir(), `reg-featured-videos-${label}-${Date.now()}.png`);
    await writeFile(path, Buffer.from(result.data, 'base64'));
    screenshots[label] = path;
  };
  const key = async (value, code, keyCode) => {
    await send('Input.dispatchKeyEvent', {type: 'keyDown', key: value, code, windowsVirtualKeyCode: keyCode});
    await send('Input.dispatchKeyEvent', {type: 'keyUp', key: value, code, windowsVirtualKeyCode: keyCode});
    await pause(120);
  };

  await send('Page.enable');
  await send('Runtime.enable');

  const viewportResults = [];
  for (const viewport of [
    {label: '1440', width: 1440, height: 1000},
    {label: '1280', width: 1280, height: 900},
    {label: '1024', width: 1024, height: 850},
    {label: '768', width: 768, height: 900},
    {label: 'mobile', width: 390, height: 844}
  ]) {
    await navigate('/', viewport.width, viewport.height);
    await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const section = document.querySelector('.video-section'); window.scrollTo(0, window.scrollY + section.getBoundingClientRect().top - 30); })()`);
    await pause(350);
    const metrics = await evaluate(`(() => {
      const section = document.querySelector('.video-section');
      const current = document.querySelector('.video-slide--current');
      const card = current?.querySelector('.video-card');
      const image = current?.querySelector('img');
      const rect = card?.getBoundingClientRect();
      const visibleSlides = Array.from(document.querySelectorAll('[data-reg-video-slide]')).filter((slide) => getComputedStyle(slide).display !== 'none');
      return {
        width: innerWidth,
        section: Boolean(section),
        noLegacyPlaceholders: !document.querySelector('.video-thumb, .video-main'),
        currentTitle: current?.dataset.regVideoTitle || '',
        imageLoaded: Boolean(image && image.complete && image.naturalWidth > 0),
        aspectRatio: rect ? rect.width / rect.height : 0,
        mainWidth: rect ? Math.round(rect.width) : 0,
        visibleSlides: visibleSlides.length,
        pagination: document.querySelectorAll('[data-reg-video-pagination]').length,
        initialIframes: section?.querySelectorAll('iframe').length || 0,
        noAutoplayMarkup: !section?.innerHTML.toLowerCase().includes('autoplay='),
        noOverflow: !section || section.scrollWidth <= innerWidth + 1,
        viewAllPath: section ? new URL(section.querySelector('.video-section__all').href).pathname : ''
      };
    })()`);
    if (viewport.label === '1440' || viewport.label === 'mobile') await capture(`homepage-${viewport.label}`);
    viewportResults.push(metrics);
  }

  await navigate('/', 1440, 1000);
  await evaluate(`window.__regVideoEvents = []; document.addEventListener('reg:analytics-track', (event) => window.__regVideoEvents.push(event.detail.event));`);
  await evaluate(`document.querySelector('[data-reg-video-next]').click()`);
  await pause(120);
  const afterNext = await evaluate(`document.querySelector('.video-slide--current').dataset.regVideoTitle`);
  await evaluate(`document.querySelector('[data-reg-video-pagination="2"]').click()`);
  await pause(120);
  const afterPagination = await evaluate(`document.querySelector('.video-slide--current').dataset.regVideoTitle`);
  await evaluate(`document.querySelector('[data-reg-video-carousel]').focus()`);
  await key('ArrowLeft', 'ArrowLeft', 37);
  const afterKeyboard = await evaluate(`document.querySelector('.video-slide--current').dataset.regVideoTitle`);
  await evaluate(`document.querySelector('.video-slide--current [data-reg-video-play]').click()`);
  await pause(200);
  const modalOpen = await evaluate(`(() => { const modal = document.querySelector('[data-reg-video-modal]'); const frame = modal.querySelector('iframe'); return {visible: !modal.hidden, iframeCount: modal.querySelectorAll('iframe').length, privacyEnhanced: frame?.src.startsWith('https://www.youtube-nocookie.com/embed/') || false, noAutoplay: !frame?.src.includes('autoplay=')}; })()`);
  await key('Escape', 'Escape', 27);
  const modalClosed = await evaluate(`(() => ({hidden: document.querySelector('[data-reg-video-modal]').hidden, iframeCount: document.querySelector('[data-reg-video-modal]').querySelectorAll('iframe').length, focusReturned: document.activeElement.matches('.video-slide--current [data-reg-video-play]')}))()`);
  const interactionEvents = await evaluate(`window.__regVideoEvents`);

  await navigate('/', 390, 844);
  const beforeSwipe = await evaluate(`document.querySelector('.video-slide--current').dataset.regVideoTitle`);
  await evaluate(`(() => { const target = document.querySelector('[data-reg-video-carousel]'); const start = new Touch({identifier: 1, target, clientX: 320, clientY: 400}); const end = new Touch({identifier: 1, target, clientX: 180, clientY: 400}); target.dispatchEvent(new TouchEvent('touchstart', {changedTouches: [start], bubbles: true})); target.dispatchEvent(new TouchEvent('touchend', {changedTouches: [end], bubbles: true})); })()`);
  await pause(150);
  const afterSwipe = await evaluate(`document.querySelector('.video-slide--current').dataset.regVideoTitle`);

  await navigate('/media/videos', 1440, 1000);
  const archive = await evaluate(`(() => ({
    cards: document.querySelectorAll('.reg-video-card').length,
    unpublishedAbsent: !document.body.textContent.includes('[TEST] REG video 04'),
    hasSearch: Boolean(document.querySelector('input[name="search"]')),
    hasCategory: Boolean(document.querySelector('select[name="category"] option[value]:not([value="0"])')),
    hasYear: Boolean(document.querySelector('select[name="year"] option[value="2026"]')),
    initialIframes: document.querySelectorAll('.reg-video-archive iframe').length,
    imagesLoaded: Array.from(document.querySelectorAll('.reg-video-card img')).every((image) => image.complete && image.naturalWidth > 0),
    categoryId: document.querySelector('select[name="category"] option[value]:not([value="0"])')?.value || ''
  }))()`);
  await evaluate(`window.scrollTo(0, 250)`);
  await pause(180);
  await capture('archive-1440');
  await evaluate(`document.querySelector('.reg-video-card [data-reg-video-play]').click()`);
  await pause(150);
  const archivePlayerDeferred = await evaluate(`document.querySelector('.reg-video-archive [data-reg-video-modal]').querySelectorAll('iframe').length === 1`);
  await key('Escape', 'Escape', 27);
  await navigate(`/media/videos?search=REG%20video%2002&category=${archive.categoryId}&year=2026`, 1024, 850);
  const filteredArchive = await evaluate(`(() => ({cards: document.querySelectorAll('.reg-video-card').length, title: document.querySelector('.reg-video-card strong')?.textContent.trim() || '', count: document.querySelector('.reg-video-archive__summary')?.textContent.trim() || ''}))()`);

  const viewportsPassed = viewportResults.every((result) => result.section && result.noLegacyPlaceholders && result.imageLoaded
    && Math.abs(result.aspectRatio - (16 / 9)) < 0.02 && result.pagination === 3 && result.initialIframes === 0
    && result.noAutoplayMarkup && result.noOverflow && result.viewAllPath === '/media/videos'
    && (result.width > 1180 ? result.visibleSlides === 3 : result.visibleSlides === 1));
  const interactionsPassed = afterNext.endsWith('02') && afterPagination.endsWith('03') && afterKeyboard.endsWith('02')
    && modalOpen.visible && modalOpen.iframeCount === 1 && modalOpen.privacyEnhanced && modalOpen.noAutoplay
    && modalClosed.hidden && modalClosed.iframeCount === 0 && modalClosed.focusReturned
    && interactionEvents.includes('video_next') && interactionEvents.includes('video_previous') && interactionEvents.includes('video_play')
    && beforeSwipe.endsWith('01') && afterSwipe.endsWith('02');
  const archivePassed = archive.cards === 3 && archive.unpublishedAbsent && archive.hasSearch && archive.hasCategory && archive.hasYear
    && archive.initialIframes === 0 && archive.imagesLoaded && archivePlayerDeferred
    && filteredArchive.cards === 1 && filteredArchive.title.endsWith('02') && filteredArchive.count.startsWith('1 ');
  const passed = viewportsPassed && interactionsPassed && archivePassed;
  console.log(JSON.stringify({passed, viewportsPassed, interactionsPassed, archivePassed, viewportResults, interactions: {afterNext, afterPagination, afterKeyboard, modalOpen, modalClosed, interactionEvents, beforeSwipe, afterSwipe}, archive, filteredArchive, screenshots}, null, 2));
  if (!passed) process.exitCode = 1;
}
finally {
  socket?.close();
  if (chrome.exitCode === null) {
    const exited = new Promise((resolve) => chrome.once('exit', resolve));
    chrome.kill();
    await Promise.race([exited, pause(2000)]);
  }
  await rm(profile, {recursive: true, force: true, maxRetries: 3, retryDelay: 100});
}
