import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, relative, isAbsolute } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9348;
const profile = await mkdtemp(join(tmpdir(), 'reg-page-heroes-'));
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
    const renderingError = await evaluate('document.body.innerText.includes("The website encountered an unexpected error")');
    if (renderingError) throw new Error("Drupal rendering failed at " + url);
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
  const routes = ['/about','/about/history','/what-we-do/generation','/media','/media/news','/media/press-releases','/media/newsletters','/sports','/sports/news','/tenders','/jobs','/branches','/contact'];
  const extended = process.argv.includes('--extended');
  const all = process.argv.includes('--all');
  if (extended || all) {
    routes.splice(0, all ? 0 : routes.length, '/customer-services', '/public-information', '/public-information/search', '/about/leadership', '/about/group/eucl', '/media/publications', '/media/videos', '/media/gallery', '/media/social-media', '/sports/fixtures', '/sports/results', '/sports/standings', '/sports/players', '/sports/gallery', '/sports/videos', '/sports/basketball-men');
    for (const seed of ['/media/news', '/sports/news', '/tenders', '/jobs', '/branches', '/media/publications', '/sports/players', '/sports/results']) {
      await navigate('http://reg-website.ddev.site' + seed, 1440, 1000);
      const detail = await evaluate("(() => {const prefix=location.pathname+'/';return Array.from(document.querySelectorAll('#main-content a[href]')).map(a=>new URL(a.href)).filter(u=>u.origin===location.origin&&!u.search).map(u=>u.pathname).find(p=>p.startsWith(prefix)&&!/\\/(?:english|kinyarwanda|current|archive|awarded|results|subscribe)$/.test(p) || /^\\/sports\\/(?:player|match)\\/\\d+$/.test(p));})()");
      if (detail && !routes.includes(detail)) routes.push(detail);
    }
    console.log('Extended routes: ' + routes.join(', '));
  }
  const selectedRoutes = process.argv.filter(value => value.startsWith('--route=')).map(value => value.slice(8));
  if (selectedRoutes.length) routes.splice(0, routes.length, ...selectedRoutes);
  const widths = selectedRoutes.length ? [1440,390] : process.argv.includes('--baseline') ? [1440] : (extended || all) ? [1440,768,390] : [1440,1280,768,390,375,430];
  const results = [];
  for (const width of widths) {
    for (const route of routes) {
      await navigate('http://reg-website.ddev.site'+route,width,1000);
      await evaluate('document.fonts.ready.then(() => true)');
      results.push(await evaluate("(() => {const hero=document.querySelector('.reg-page-hero, .reg-section-hero, .reg-sports-hero, .reg-news-article__header');const title=hero?.querySelector('h1');const image=hero?.querySelector('img');return {route:location.pathname,width:innerWidth,height:hero?.getBoundingClientRect().height,classes:hero?.className,h1:title?.textContent.trim(),font:title&&getComputedStyle(title).fontSize,h1Count:document.querySelectorAll('h1').length,heroCount:document.querySelectorAll('.reg-page-hero, .reg-section-hero, .reg-sports-hero, .reg-news-article__header').length,overflow:document.documentElement.scrollWidth>innerWidth,heroOverflow:title?title.getBoundingClientRect().right>innerWidth:false,imageLoaded:image?image.complete&&image.naturalWidth>0:null};})()"));
      if ([1440,768,390].includes(width) && ['/about','/what-we-do/generation','/media/news','/sports/news','/sports','/tenders','/contact'].includes(route) || [1440,390].includes(width) && /\/(?:news|tenders|player|match)\/\d+$/.test(route)) {
        await screenshot(join(tmpdir(),'reg-hero-'+route.replaceAll('/','-')+'-'+width+'.png'));
      }
    }
    console.log('Checked ' + routes.length + ' routes at ' + width + 'px.');
  }
  const output = join(tmpdir(),process.argv.includes('--baseline')?'reg-heroes-before.json':(extended||all)?'reg-heroes-extended.json':'reg-heroes-after.json');
  await writeFile(output,JSON.stringify(results,null,2));
  console.log(JSON.stringify({output, checks: results.length, failures: results.filter(r=>!r.height||r.h1Count!==1||r.heroCount!==1||r.heroOverflow||r.overflow||r.imageLoaded===false)},null,2));
  if(results.some(r=>!r.height||r.h1Count!==1||r.heroCount!==1||r.heroOverflow||r.overflow||r.imageLoaded===false))process.exitCode=1;
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
      const relativeProfile = relative(resolve(tmpdir()), resolve(profile));
      if (!relativeProfile || relativeProfile.startsWith('..') || isAbsolute(relativeProfile)) throw new Error('Browser profile is outside the temporary directory.');
      await rm(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 });
      break;
    }
    catch (error) {
      if (attempt === 9) throw error;
      await pause(150);
    }
  }
}
