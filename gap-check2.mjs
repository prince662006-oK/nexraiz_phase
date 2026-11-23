import { writeFileSync } from 'node:fs';
async function rpc(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const handler = (msg) => {
      const data = JSON.parse(msg.data);
      if (data.id === id) { ws.removeEventListener('message', handler); if (data.error) reject(new Error(JSON.stringify(data.error))); else resolve(data.result); }
    };
    ws.addEventListener('message', handler);
    ws.send(JSON.stringify({ id, method, params }));
  });
}
const verJson = await (await fetch('http://127.0.0.1:9333/json/version')).json();
const browserWs = new WebSocket(verJson.webSocketDebuggerUrl);
await new Promise((r) => browserWs.addEventListener('open', r));
let idc = 1;
const { targetId } = await rpc(browserWs, idc++, 'Target.createTarget', { url: 'about:blank' });
const { sessionId } = await rpc(browserWs, idc++, 'Target.attachToTarget', { targetId, flatten: true });
async function srpc(method, params = {}) {
  return new Promise((resolve, reject) => {
    const myId = idc++;
    const handler = (msg) => {
      const data = JSON.parse(msg.data);
      if (data.sessionId === sessionId && data.id === myId) { browserWs.removeEventListener('message', handler); if (data.error) reject(new Error(JSON.stringify(data.error))); else resolve(data.result); }
    };
    browserWs.addEventListener('message', handler);
    browserWs.send(JSON.stringify({ id: myId, method, params, sessionId }));
  });
}
async function evalJs(expr) {
  const r = await srpc('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
  if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
  return r.result.value;
}
await srpc('Page.enable');
await srpc('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
await srpc('Page.navigate', { url: 'http://localhost/nexraiz_phase/decouverte.php' });
await new Promise((r) => setTimeout(r, 5000));
const info = await evalJs(`(function(){
  var cards = document.querySelectorAll('.situation-card');
  var first = cards[0].getBoundingClientRect();
  var last = cards[cards.length-1].getBoundingClientRect();
  var grid = document.getElementById('situationsGrid').getBoundingClientRect();
  var aside = document.getElementById('situationsGrid').nextElementSibling.getBoundingClientRect();
  return JSON.stringify({
    cardsSpan: last.bottom - first.top,
    gridHeight: grid.height,
    asideHeight: aside.height,
    emptyInGrid: grid.height - (last.bottom - first.top)
  });
})()`);
console.log('INFO:', info);
process.exit(0);
