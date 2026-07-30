/**
 * capture-graphql-query.mjs
 * -----------------------------------------------------------------------------
 * SANADOR GENERICO para las consultas GraphQL internas (Facebook, Instagram, X).
 *
 * Se conecta al navegador de depuracion ya abierto (puerto 9222), abre una
 * pestana nueva, navega a un perfil/pagina, captura la request GraphQL indicada
 * y guarda sus valores frescos (doc_id, __rev, __hs y las provider variables)
 * en el archivo de salida. Cierra solo esa pestana; no toca tu navegador.
 *
 * Es la version parametrizable de lo que antes era capture-fb-query.mjs, para
 * reusar el mismo codigo en las 3 plataformas.
 *
 * USO:
 *   node scripts/capture-graphql-query.mjs <friendly_name> <out_file> <url>
 *
 * EJEMPLOS:
 *   node scripts/capture-graphql-query.mjs \
 *     ProfileCometTimelineFeedRefetchQuery \
 *     storage/app/facebook-graphql.json \
 *     https://www.facebook.com/rppnoticias
 *
 *   node scripts/capture-graphql-query.mjs \
 *     PolarisProfilePostsTabContentQuery_connection \
 *     storage/app/instagram-graphql.json \
 *     https://www.instagram.com/rppnoticias
 *
 * REQUISITO: el navegador 9222 abierto y con sesion iniciada en esa plataforma.
 * -----------------------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';

const PORT = Number(process.env.SCRAPER_DEBUG_PORT || 9222);
const FRIENDLY = process.argv[2];
const OUT_FILE = process.argv[3] ? path.resolve(process.argv[3]) : null;
const PAGE_URL = process.argv[4];

const log = (m) => console.log(`[capture-graphql-query] ${m}`);
const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

if (!FRIENDLY || !OUT_FILE || !PAGE_URL) {
  log('ERROR: faltan argumentos. Uso: node capture-graphql-query.mjs <friendly_name> <out_file> <url>');
  process.exit(1);
}

// Cliente CDP minimo (enviar comando y esperar respuesta).
function crearCliente(ws) {
  let id = 1;
  const pend = new Map();
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pend.has(m.id)) { pend.get(m.id)(m.result); pend.delete(m.id); }
  });
  return (metodo, params = {}) => {
    const _id = id++;
    ws.send(JSON.stringify({ id: _id, method: metodo, params }));
    return new Promise((res) => pend.set(_id, res));
  };
}

async function main() {
  fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });

  // 1. Conectar al navegador ya abierto.
  let version;
  try {
    version = await (await fetch(`http://localhost:${PORT}/json/version`)).json();
  } catch {
    log(`ERROR: no hay navegador de depuracion en el puerto ${PORT}. Abrelo con "make chrome" y dejalo abierto.`);
    process.exit(1);
  }
  const browserWs = new WebSocket(version.webSocketDebuggerUrl);
  const enviarBrowser = crearCliente(browserWs);
  await new Promise((r) => browserWs.addEventListener('open', r));

  // 2. Abrir una pestana nueva.
  const { targetId } = await enviarBrowser('Target.createTarget', { url: 'about:blank' });
  const cerrarPestana = async () => {
    try { await enviarBrowser('Target.closeTarget', { targetId }); } catch { /* nada */ }
  };

  // 3. Ubicar el websocket de esa pestana.
  let pageWsUrl = null;
  for (let i = 0; i < 20 && !pageWsUrl; i++) {
    try {
      const lista = await (await fetch(`http://localhost:${PORT}/json`)).json();
      const t = lista.find((x) => x.id === targetId);
      if (t?.webSocketDebuggerUrl) { pageWsUrl = t.webSocketDebuggerUrl; break; }
    } catch { /* reintentar */ }
    await esperar(300);
  }
  if (!pageWsUrl) {
    log('ERROR: no pude abrir la pestana nueva en el navegador.');
    await cerrarPestana();
    process.exit(1);
  }

  // 4. Conectar a la pestana y capturar la red.
  const ws = new WebSocket(pageWsUrl);
  const enviar = crearCliente(ws);

  const graphqlBodies = [];
  ws.addEventListener('message', async (ev) => {
    const m = JSON.parse(ev.data);
    if (m.method !== 'Network.requestWillBeSent') return;
    const { requestId, request } = m.params;
    if (!request.url.includes('/graphql')) return; // sirve para /api/graphql (FB) y /graphql/query (IG)

    if (request.postData) {
      graphqlBodies.push(request.postData);
    } else if (request.hasPostData) {
      try {
        const r = await enviar('Network.getRequestPostData', { requestId });
        if (r?.postData) graphqlBodies.push(r.postData);
      } catch { /* Chrome ya lo descarto */ }
    }
  });

  await new Promise((r) => ws.addEventListener('open', r));
  await enviar('Network.enable');
  await enviar('Page.enable');

  log(`Navegando a ${PAGE_URL} (pestana nueva)`);
  await enviar('Page.navigate', { url: PAGE_URL });
  await esperar(4000);

  // Esperar (con scroll) hasta capturar la request indicada, o ~40s.
  const encontrar = () => graphqlBodies.find((b) => b.includes(`fb_api_req_friendly_name=${FRIENDLY}`));
  let body = encontrar();
  for (let i = 0; i < 18 && !body; i++) {
    await enviar('Runtime.evaluate', { expression: 'window.scrollBy(0, Math.max(document.body.scrollHeight, 3000))' });
    await esperar(2000);
    body = encontrar();
  }

  if (!body) {
    const nombres = [...new Set(graphqlBodies
      .map((b) => (b.match(/fb_api_req_friendly_name=([^&]+)/) || [])[1])
      .filter(Boolean))];
    log(`ERROR: no se capturo ${FRIENDLY}. GraphQL vistas: ${graphqlBodies.length}. Nombres: ${nombres.join(', ').slice(0, 500) || '(ninguna)'}`);
    await cerrarPestana();
    process.exit(1);
  }

  await cerrarPestana();

  // 5. Parsear los valores frescos.
  const params = new URLSearchParams(body);
  const docId = params.get('doc_id');
  const rev = params.get('__rev');
  const hs = params.get('__hs');

  const providers = {};
  try {
    const vars = JSON.parse(params.get('variables') || '{}');
    for (const [k, v] of Object.entries(vars)) {
      if (k.startsWith('__relay_internal__pv__')) providers[k] = v;
    }
  } catch (e) {
    log('ERROR: no se pudieron parsear las variables: ' + e.message);
    process.exit(1);
  }

  if (!docId) {
    log('ERROR: no se encontro doc_id en la request capturada.');
    process.exit(1);
  }

  fs.writeFileSync(OUT_FILE, JSON.stringify({
    doc_id: docId,
    rev,
    hs,
    providers,
    captured_at: new Date().toISOString(),
    source_url: PAGE_URL,
    friendly_name: FRIENDLY,
  }, null, 2));

  log(`OK: doc_id=${docId} · ${Object.keys(providers).length} providers · guardado en ${OUT_FILE}`);
  process.exit(0);
}

main().catch((e) => {
  log('ERROR: ' + e.message);
  process.exit(1);
});
