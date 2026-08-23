import { spawn } from 'node:child_process';
import { writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9342;
const profile = await mkdtemp(join(tmpdir(), 'reg-sports-rosters-'));
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
  const navigate = async (path, width, height, mobile = false) => {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    await send('Page.navigate', { url: `https://reg-website.ddev.site${path}` });
    await ready();
  };
  const capture = async (label) => {
    const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false, fromSurface: true });
    const path = join(tmpdir(), `reg-sports-rosters-${label}-${Date.now()}.png`);
    await writeFile(path, Buffer.from(result.data, 'base64'));
    screenshots[label] = path;
  };
  const inspectTeam = () => evaluate(`(() => {
    const cards = Array.from(document.querySelectorAll('.reg-sports-player-grid .reg-sports-player-card'));
    const tableRow = document.querySelector('.reg-sports-table tbody tr');
    const dl = document.querySelector('.reg-sports-team-hero dl')?.innerText || '';
    const body = document.querySelector('main')?.innerText || '';
    return {
      title: document.querySelector('.reg-sports-team-hero h1')?.textContent.trim() || '',
      heroMetadata: dl,
      playerNames: cards.map((card) => card.querySelector('h3')?.textContent.trim()),
      captains: cards.filter((card) => card.querySelector('small')?.textContent.trim() === 'Captain').map((card) => card.querySelector('h3')?.textContent.trim()),
      blankMetadataElements: cards.filter((card) => card.querySelector('span') && !card.querySelector('span').textContent.trim()).length,
      inventedJerseyLabels: cards.filter((card) => (card.querySelector('span')?.textContent || '').includes('#')).length,
      standingCells: Array.from(tableRow?.querySelectorAll('th, td') || []).map((cell) => cell.textContent.trim()),
      internalMetadataExposed: body.includes('Administrator verification notes') || body.includes('Needs administrator review') || body.includes('Another verified source'),
      noHorizontalOverflow: document.documentElement.scrollWidth === document.documentElement.clientWidth,
      minimumCardWidth: cards.length ? Math.round(Math.min(...cards.map((card) => card.getBoundingClientRect().width))) : 0,
    };
  })()`);

  await send('Page.enable');
  await send('Runtime.enable');

  await navigate('/sports', 1440, 900);
  const landingTeams = await evaluate(`Array.from(document.querySelectorAll('.reg-sports-team-card h3')).map((heading) => heading.textContent.trim())`);

  await navigate('/sports/basketball-men', 1440, 1000);
  const menDesktop = await inspectTeam();
  await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const grid = document.querySelector('.reg-sports-player-grid'); if (grid) window.scrollTo(0, grid.getBoundingClientRect().top + scrollY - 100); })()`);
  await pause(100);
  await capture('men-desktop');

  await navigate('/sports/basketball-women', 1440, 1000);
  const womenDesktop = await inspectTeam();
  await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const grid = document.querySelector('.reg-sports-player-grid'); if (grid) window.scrollTo(0, grid.getBoundingClientRect().top + scrollY - 100); })()`);
  await pause(100);
  await capture('women-desktop');

  await navigate('/sports/basketball-men', 390, 844, true);
  const menMobile = await inspectTeam();
  await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const grid = document.querySelector('.reg-sports-player-grid'); if (grid) window.scrollTo(0, grid.getBoundingClientRect().top + scrollY - 90); })()`);
  await pause(100);
  await capture('men-mobile');

  await navigate('/sports/basketball-women', 390, 844, true);
  const womenMobile = await inspectTeam();
  await evaluate(`(() => { document.documentElement.style.scrollBehavior = 'auto'; const grid = document.querySelector('.reg-sports-player-grid'); if (grid) window.scrollTo(0, grid.getBoundingClientRect().top + scrollY - 90); })()`);
  await pause(100);
  await capture('women-mobile');

  const menExpected = [
    'Elliott Lamar Cole', 'Patrick Nshizirungu', 'Jean de Dieu Umuhoza', 'Enock Isezerano',
    'Cadeau de Dieu Furaha', 'Frank Kamndoh Betoudji', 'Fabrice Muhoza', 'Armel Sangwe',
    'Prince Muhizi', 'Emile Kazeneza', 'Hamady Barro Ndiaye', 'Sedar Sagamba',
    'Hubert Kabare Bugingo', 'Yves Shema', 'Garmine Kande Kieli', 'Steve Junior Nsumizi',
  ];
  const womenExpected = [
    'Faustine Mwizerwa', 'Henriette Uwimpuhwe', 'Ange Nelly Irakoze', 'Sandrine Mushikiwabo',
    'Odile Tetero', 'Sandra Kantoré', 'Lamla Umunezero', 'Chantal Ramu Kiyobe',
    'Shauqunna Nicole Collins', 'Marie Chantal Utamuliza', 'Kankou Coulibaly', 'Nandy',
    'Kadidia Maiga', 'Taylor Lynn Hosendove',
  ];
  const canonical = (actual, expected) => JSON.stringify(actual) === JSON.stringify(expected);
  const menPassed = [menDesktop, menMobile].every((result) =>
    result.title === 'REG Basketball Men'
    && result.heroMetadata.includes('2026 Rwanda Basketball League')
    && result.heroMetadata.includes('2026')
    && canonical(result.playerNames, menExpected)
    && JSON.stringify(result.captains) === JSON.stringify(['Prince Muhizi'])
    && result.blankMetadataElements === 0 && result.inventedJerseyLabels === 0
    && JSON.stringify(result.standingCells.slice(0, 5)) === JSON.stringify(['3', 'REG Basketball Men', '16', '11', '5'])
    && !result.internalMetadataExposed && result.noHorizontalOverflow
  );
  const womenPassed = [womenDesktop, womenMobile].every((result) =>
    result.title === 'REG Basketball Women'
    && result.heroMetadata.includes('2026')
    && !result.heroMetadata.includes('2026 Rwanda Basketball League')
    && canonical(result.playerNames, womenExpected)
    && result.captains.length === 0
    && result.standingCells.length === 0
    && result.blankMetadataElements === 0 && result.inventedJerseyLabels === 0
    && !result.internalMetadataExposed && result.noHorizontalOverflow
  );
  const passed = landingTeams.includes('REG Basketball Men') && landingTeams.includes('REG Basketball Women') && menPassed && womenPassed;
  console.log(JSON.stringify({ passed, menPassed, womenPassed, landingTeams, menDesktop, womenDesktop, menMobile, womenMobile, screenshots }, null, 2));
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
