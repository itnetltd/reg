import {lookup} from 'node:dns/promises';
// Run through DDEV with REG_BROWSER_CDP pointing to an isolated Chrome endpoint.
import {mkdir, writeFile} from 'node:fs/promises';
import {join} from 'node:path';
const endpoint = 'http://' + (await lookup('host.docker.internal',{family:4})).address + ':9779';
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



  const results=[];
  for (const width of [1920,1440,1366,1024,768,390]) {
    await navigate('/',width,1000);
    const result=await evaluate(`(()=>{const section=document.querySelector('.section--tenders');const card=section.querySelector('.reg-tender-feature-card');const title=card.querySelector('h3');return {width,sectionHeight:Math.round(section.getBoundingClientRect().height),cardHeight:Math.round(card.getBoundingClientRect().height),cardWidth:Math.round(card.getBoundingClientRect().width),titleSize:getComputedStyle(title).fontSize,clamp:getComputedStyle(title).webkitLineClamp,title:title.textContent,meta:Array.from(card.querySelectorAll('dd')).map(e=>e.textContent),badges:card.querySelector('.reg-tender-feature-card__top').innerText,links:Array.from(card.querySelectorAll('a')).map(e=>({text:e.textContent.trim(),href:e.getAttribute('href')})),overflow:document.documentElement.scrollWidth>innerWidth}})()`.replace('{width,','{width:innerWidth,'));
    console.log(JSON.stringify(result));results.push(result);
    await evaluate("document.documentElement.style.scrollBehavior='auto';document.querySelector('.section--tenders').scrollIntoView()");
    await pause(300);
    const rect=await evaluate("(()=>{const r=document.querySelector('.section--tenders').getBoundingClientRect();return {x:0,y:r.top+scrollY,width:innerWidth,height:r.height,scale:1}})()");
    const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:rect});
    await writeFile(join(outputDirectory,'tenders-'+width+'.png'),Buffer.from(shot.data,'base64'));
  }
  await writeFile(join(outputDirectory,'results.json'),JSON.stringify(results,null,2));
  await navigate('/',1440,1000);
  for(const count of [2,3]) {
    console.log('layout '+JSON.stringify(await evaluate(`(()=>{const grid=document.querySelector('.reg-tender-feature-grid');grid.className='reg-tender-feature-grid reg-tender-feature-grid--count-${count}';while(grid.children.length<${count})grid.append(grid.firstElementChild.cloneNode(true));return {count:${count},columns:getComputedStyle(grid).gridTemplateColumns,cards:Array.from(grid.children).map(c=>({height:c.offsetHeight,width:c.offsetWidth,clamp:getComputedStyle(c.querySelector('h3')).webkitLineClamp,actionsVisible:c.querySelector('.reg-tender-feature-card__actions').getBoundingClientRect().bottom<=c.getBoundingClientRect().bottom}))}})()`)));
  }
  await navigate(results[0].links[0].href,1440,1000);
  console.log('detail '+JSON.stringify(await evaluate("({heading:document.querySelector('h1')?.textContent,detail:!!document.querySelector('.reg-tender-detail'),homepage:!!document.querySelector('.site-shell--front')})")));
} finally {socket.close();}