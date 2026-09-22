// Run through DDEV with REG_BROWSER_CDP pointing to an isolated Chrome endpoint.
import {mkdir, writeFile} from 'node:fs/promises';
import {join} from 'node:path';
const endpoint = process.env.REG_BROWSER_CDP || 'http://host.docker.internal:9778';
const outputDirectory = process.env.REG_BROWSER_OUTPUT || '/tmp/reg-homepage-review';
await mkdir(outputDirectory, {recursive: true});
const targets = await (await fetch(endpoint + '/json/list')).json();
const target = targets.find(item => item.type === 'page');
if (!target) throw new Error('No isolated browser page available.');
const wsUrl = new URL(target.webSocketDebuggerUrl);
wsUrl.host = new URL(endpoint).host;
const socket = new WebSocket(wsUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, {once:true});
  socket.addEventListener('error', reject, {once:true});
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const screenshots = {};
const chromeErrors = '';
try {
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
    const path = join(outputDirectory, `homepage-${label}.png`);
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


  const inspect = () => {
    const services = document.querySelector('.quick-services-grid');
    const cards = Array.from(services.querySelectorAll('.quick-service'));
    const energy = document.querySelector('.energy-awareness-section');
    const education = energy.querySelector('.education-video');
    const portal = cards[0].querySelector('a');
    return {
      width: innerWidth,
      titles: cards.map(card => card.querySelector('h3').textContent),
      tags: cards[0].querySelectorAll('li').length,
      url: portal.href, external: portal.target === '_blank' && portal.rel.includes('noopener'),
      noDuplicateCards: !cards.some(card => /New Connection|Pay Bill|Bill Estimator/.test(card.querySelector('h3').textContent)),
      tools: Array.from(energy.querySelectorAll('.energy-tool-card h3')).map(el => el.textContent),
      statuses: Array.from(energy.querySelectorAll('.energy-tool-card__status')).map(el => el.textContent.trim()),
      overflow: document.documentElement.scrollWidth > innerWidth,
      columns: getComputedStyle(services).gridTemplateColumns.split(' ').length,
      featuredFullWidth: Math.abs(cards[0].getBoundingClientRect().width - services.getBoundingClientRect().width) < 2,
      video: !!education,
      thumbnailFilled: !education || Math.abs(education.querySelector("img").getBoundingClientRect().height - education.querySelector(".education-video__thumbnail").getBoundingClientRect().height) < 2,
      energyColumns: getComputedStyle(energy.querySelector('.energy-tools-grid')).gridTemplateColumns.split(' ').length,
      sectionHeight: Math.round(energy.getBoundingClientRect().height),
      cardHeights: Array.from(energy.querySelector('.energy-tools-grid').children).map(el => Math.round(el.getBoundingClientRect().height)),
      videoInGrid: !education || education.parentElement.matches('.energy-tools-grid'),
      thumbnailLoaded: !education || (education.querySelector('img').complete && education.querySelector('img').naturalWidth > 0),
      iframesBeforeClick: energy.querySelectorAll('iframe').length
    };
  };
  const results = [];
  for (const width of [1440, 1280, 768, 390, 320]) {
    await navigate('/', width, 1000);
    await evaluate("document.querySelector('.energy-awareness-section').scrollIntoView();");
    await evaluate("Promise.all(Array.from(document.querySelectorAll('.energy-awareness-section img')).map(img => img.complete ? Promise.resolve() : new Promise(resolve => { img.addEventListener('load', resolve, {once:true}); img.addEventListener('error', resolve, {once:true}); setTimeout(resolve, 5000); })))");
    const metrics = await evaluate('(' + inspect.toString() + ')()');
    for (const [label, selector] of [['services','.section--services'], ['energy','.energy-awareness-section']]) {
      await evaluate("document.documentElement.style.scrollBehavior = 'auto'; document.querySelector(" + JSON.stringify(selector) + ").scrollIntoView(); window.scrollBy(0, -130)");
      await pause(200);
      await capture(label + '-' + width);
    }
    const expectedVideo = process.env.REG_EXPECT_EDUCATION === '1';
    metrics.passed = JSON.stringify(metrics.titles) === JSON.stringify(['Online Services','Check Outages','Branch Locator','Help & FAQs'])
      && metrics.tags === 8 && metrics.external && metrics.url === 'https://online.reg.rw/'
      && metrics.noDuplicateCards && !metrics.overflow && metrics.featuredFullWidth
      && metrics.columns === (width <= 760 ? 1 : 3)
      && JSON.stringify(metrics.tools) === JSON.stringify(['Bill Estimator','Carbon Footprint','Safety & Efficiency'])
      && metrics.statuses[0] === 'Available' && metrics.statuses[1] === 'Awaiting approved factor'
      && metrics.video === expectedVideo && metrics.thumbnailFilled && metrics.iframesBeforeClick === 0
      && metrics.videoInGrid && metrics.thumbnailLoaded
      && metrics.energyColumns === (width <= 600 ? 1 : width <= 1100 ? 2 : expectedVideo ? 4 : 3)
      && (width <= 1100 || metrics.sectionHeight <= 450 && metrics.cardHeights.every(height => height <= 310));
    results.push(metrics);
  }
  let playback = null;
  if (process.env.REG_EXPECT_EDUCATION === '1') {
    await evaluate("document.querySelector('.education-video__cta').click()");
    const opened = await evaluate("!document.querySelector('.education-video [data-reg-video-modal]').hidden && document.querySelectorAll('.education-video iframe').length === 1");
    const correctPlayer = await evaluate("document.querySelector('.education-video iframe').src === document.querySelector('.education-video [data-reg-video-item]').dataset.regVideoEmbedUrl");
    const noAutoplay = await evaluate("!new URL(document.querySelector('.education-video iframe').src).searchParams.has('autoplay') && !document.querySelector('.education-video iframe').allow.includes('autoplay')");
    await key('Escape', 'Escape', 27);
    const closed = await evaluate("document.querySelector('.education-video [data-reg-video-modal]').hidden && document.querySelectorAll('.education-video iframe').length === 0 && document.activeElement.matches('.education-video__cta')");
    playback = {opened, closed, correctPlayer, noAutoplay};
  }
  const passed = results.every(item => item.passed) && (!playback || playback.opened && playback.closed && playback.correctPlayer && playback.noAutoplay);
  console.log(JSON.stringify({passed, results, playback, screenshots}, null, 2));
  if (!passed) process.exitCode = 1;
}
finally { socket.close(); }
