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
    await evaluate("document.documentElement.style.scrollBehavior='auto';document.querySelector('.homepage-work').scrollIntoView()");
    await pause(700);
    const result=await evaluate(`(()=>{const section=document.querySelector('.homepage-work');const grid=section.querySelector('.homepage-work__grid');return {width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth,columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,cards:Array.from(grid.children).map(card=>{const title=card.querySelector('h3'),summary=card.querySelector('p'),cta=card.querySelector('.homepage-work__explore'),media=card.querySelector('.homepage-work__image'),img=card.querySelector('img'),rect=card.getBoundingClientRect(),action=cta.getBoundingClientRect();return {cardWidth:Math.round(rect.width),title:title.textContent,height:Math.round(rect.height),imageHeight:Math.round(media.getBoundingClientRect().height),layout:getComputedStyle(card).display,fit:getComputedStyle(img).objectFit,loaded:img.naturalWidth>0,titleVisible:title.scrollWidth<=title.clientWidth+1&&title.scrollHeight<=title.clientHeight+1,ctaVisible:action.bottom<=rect.bottom&&action.right<=rect.right&&action.left>=rect.left,clamp:getComputedStyle(summary).webkitLineClamp,summaryHeight:summary.getBoundingClientRect().height,summaryLineHeight:parseFloat(getComputedStyle(summary).lineHeight)}})}})()`);
    result.passed=!result.overflow&&result.columns===(width<=640?1:2)&&result.cards.length===4&&result.cards.every((c,index)=>c.titleVisible&&c.ctaVisible&&c.loaded&&c.fit===([0,2].includes(index)?'contain':'cover')&&c.clamp===(width<=640?'3':'2')&&c.summaryHeight<=c.summaryLineHeight*(width<=640?3:2)+1&&c.layout===(width<=960?'flex':'grid')&&(width>960||c.imageHeight>=180&&c.imageHeight<=220));
    results.push(result);
    if([1440,768,390].includes(width)) {
      const rect=await evaluate("(()=>{const r=document.querySelector('.homepage-work').getBoundingClientRect();return {x:0,y:r.top+scrollY,width:innerWidth,height:r.height,scale:1}})()");
      const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:rect});
      await writeFile(join(outputDirectory,'work-'+width+'.png'),Buffer.from(shot.data,'base64'));
    }
  }
  await writeFile(join(outputDirectory,'results.json'),JSON.stringify(results,null,2));
  console.log(JSON.stringify(results.map(r=>({width:r.width,columns:r.columns,heights:r.cards.map(c=>c.height),imageHeight:r.cards[0].imageHeight,clamp:r.cards[0].clamp,passed:r.passed})),null,2));
  if(results.some(r=>!r.passed))process.exitCode=1;
  await navigate('/',1440,1000);
  await evaluate("document.documentElement.style.scrollBehavior='auto';document.querySelector('.homepage-work').scrollIntoView();window.scrollBy(0,-150)");
  const position=await evaluate("(()=>{const r=document.querySelectorAll('.homepage-work__card')[1].getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()");
  await send('Input.dispatchMouseEvent',{type:'mouseMoved',...position});
  await pause(450);
  const hover=await evaluate("(()=>{const card=document.querySelectorAll('.homepage-work__card')[1];return {card:getComputedStyle(card).transform,image:getComputedStyle(card.querySelector('img')).transform,arrow:getComputedStyle(card.querySelector('.homepage-work__arrow')).transform,duration:getComputedStyle(card).transitionDuration,radius:getComputedStyle(card).borderRadius,background:getComputedStyle(document.querySelector('.homepage-work')).backgroundColor}})()");
  if(hover.card!=='matrix(1, 0, 0, 1, 0, -2)'||hover.image!=='matrix(1.02, 0, 0, 1.02, 0, 0)'||hover.arrow!=='matrix(1, 0, 0, 1, 3, 0)')throw new Error('Unexpected hover behavior '+JSON.stringify(hover));
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  const reduced=await evaluate("(()=>{const card=document.querySelectorAll('.homepage-work__card')[1];return {card:getComputedStyle(card).transform,image:getComputedStyle(card.querySelector('img')).transform,duration:getComputedStyle(card).transitionDuration}})()");
  if(reduced.card!=='none'||reduced.image!=='none'||reduced.duration!=='0s')throw new Error('Reduced motion check failed');
  await writeFile(join(outputDirectory,'hover.json'),JSON.stringify({hover,reduced},null,2));
  console.log(JSON.stringify({hover,reduced}));
}finally{socket.close();}