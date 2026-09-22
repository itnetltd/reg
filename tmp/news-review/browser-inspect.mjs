import {lookup} from 'node:dns/promises';
// Run through DDEV with REG_BROWSER_CDP pointing to an isolated Chrome endpoint.
import {mkdir, writeFile} from 'node:fs/promises';
import {join} from 'node:path';
const endpoint = 'http://' + (await lookup('host.docker.internal',{family:4})).address + ':9778';
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



  await navigate('/',1440,1000);
  await evaluate("document.querySelector('.section--news').scrollIntoView()");
  await pause(1200);
  console.log(JSON.stringify(await evaluate("Array.from(document.querySelectorAll('.reg-news-homepage img')).map(img=>({html:img.outerHTML,src:img.currentSrc,complete:img.complete,width:img.naturalWidth,height:img.naturalHeight,rect:img.getBoundingClientRect().toJSON()}))"),null,2));
}finally{socket.close();}