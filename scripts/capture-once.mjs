/**
 * capture-once.mjs
 * -----------------------------------------------------------------------------
 * Version "de una sola pasada" pensada para AUTOMATIZAR (cron cada hora).
 *
 * Hace solito lo que antes haciamos entre watch-network + cookies-dump + yo
 * navegando:
 *
 *   1. Lanza un Chrome HEADLESS (sin ventana) con tu perfil logueado.
 *   2. Se conecta por CDP y activa la captura de red.
 *   3. Navega a la pagina objetivo (tu perfil por defecto).
 *   4. Espera unos segundos a que carguen las requests (incl. GraphQL).
 *   5. Guarda: todas las requests con su detalle + todas las cookies.
 *   6. Cierra el Chrome headless y termina.
 *
 * Cada corrida guarda en su propia carpeta con fecha/hora:
 *   storage/captures/2026-07-22_15-00-00/
 *       network.jsonl   (una request por linea, con headers/payload/response)
 *       cookies.json    (todas las cookies, incl. httpOnly de sesion)
 *       resumen.json    (metadatos: url, cuantas requests, etc.)
 *
 * USO MANUAL:
 *   node scripts/capture-once.mjs
 *   node scripts/capture-once.mjs "https://www.facebook.com/otro"   # otra URL
 *
 * IMPORTANTE (perfil compartido):
 *   Usa el MISMO perfil que la ventana interactiva ($HOME/.chrome-debug). Chrome
 *   NO permite dos instancias sobre el mismo perfil a la vez, asi que NO corras
 *   esto mientras tengas abierta la ventana de `make chrome`. Para cron normal
 *   (cuando no estas usando el navegador) no hay problema.
 *
 * SEGURIDAD: las cookies guardadas son tu sesion. storage/captures/ esta en
 * .gitignore. Trata esos archivos como secretos.
 * -----------------------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawn, execSync } from 'node:child_process';

// --- Configuracion ----------------------------------------------------------
const PORT = 9333; // puerto propio para el headless (distinto del 9222 interactivo)
const PROFILE = path.join(os.homedir(), '.chrome-debug'); // perfil con tu login
const TARGET_URL = process.argv[2] || 'https://www.facebook.com/profile.php?id=61586343923018';
const SEGUNDOS_CAPTURA = 12; // cuanto esperamos tras navegar para juntar requests
const RETENCION_DIAS = 7;    // auto-limpieza: borra capturas mas viejas que esto

const c = { reset: '\x1b[0m', gris: '\x1b[90m', verde: '\x1b[32m', rojo: '\x1b[31m', cyan: '\x1b[36m', negrita: '\x1b[1m' };
const log = (m) => console.log(`${c.gris}[${new Date().toISOString()}]${c.reset} ${m}`);

// Carpeta de salida con fecha-hora (ej: 2026-07-22_15-00-00)
const sello = new Date().toISOString().replace('T', '_').replace(/:/g, '-').slice(0, 19);
const OUT_DIR = path.resolve('storage/captures', sello);

// Espera pasiva (helper)
const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

// --- Lanzar Chrome headless -------------------------------------------------
let chromeProc = null;
function lanzarChrome() {
  log(`Lanzando Chrome headless (perfil: ${PROFILE})...`);
  chromeProc = spawn(
    'google-chrome',
    [
      '--headless=new',
      '--disable-gpu',
      `--remote-debugging-port=${PORT}`,
      `--user-data-dir=${PROFILE}`,
      '--no-first-run',
      '--no-default-browser-check',
      '--window-size=1280,1800',
      'about:blank',
    ],
    { stdio: 'ignore', detached: true }
  );
  chromeProc.unref();
}

// Cierra el headless que lanzamos (por su puerto, para no matar otros Chrome).
function cerrarChrome() {
  try {
    execSync(`pkill -f "remote-debugging-port=${PORT}"`);
    log('Chrome headless cerrado.');
  } catch {
    /* si no habia nada que matar, no pasa nada */
  }
}

// Auto-limpieza: borra carpetas de captura mas viejas que RETENCION_DIAS.
// Asi el disco se regula solo (importante para un cron cada hora).
function limpiarCapturasViejas() {
  const base = path.resolve('storage/captures');
  if (!fs.existsSync(base)) return;
  const limite = Date.now() - RETENCION_DIAS * 24 * 60 * 60 * 1000;
  let borradas = 0;
  for (const nombre of fs.readdirSync(base)) {
    const p = path.join(base, nombre);
    try {
      const st = fs.statSync(p);
      if (st.isDirectory() && st.mtimeMs < limite) {
        fs.rmSync(p, { recursive: true, force: true });
        borradas++;
      }
    } catch {
      /* ignoramos carpetas que no se puedan leer/borrar */
    }
  }
  if (borradas) log(`Limpieza: ${borradas} captura(s) de mas de ${RETENCION_DIAS} dias borradas.`);
}

// Espera a que el puerto de depuracion responda y devuelve la pestana.
async function esperarPagina() {
  for (let i = 0; i < 40; i++) {
    try {
      const lista = await (await fetch(`http://localhost:${PORT}/json`)).json();
      const pagina = lista.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (pagina) return pagina;
    } catch {
      /* aun no arranca; reintentamos */
    }
    await esperar(500);
  }
  throw new Error('Chrome headless no respondio a tiempo (¿perfil en uso por otra ventana?).');
}

// --- Cliente CDP minimo (enviar comando y esperar respuesta) ----------------
function crearCliente(ws) {
  let id = 1;
  const pendientes = new Map();
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pendientes.has(msg.id)) {
      pendientes.get(msg.id)(msg.result);
      pendientes.delete(msg.id);
    }
  });
  return {
    enviar(metodo, params = {}) {
      const _id = id++;
      ws.send(JSON.stringify({ id: _id, method: metodo, params }));
      return new Promise((resolve) => pendientes.set(_id, resolve));
    },
    onEvento: null, // se asigna afuera
  };
}

// --- Programa principal -----------------------------------------------------
async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  lanzarChrome();

  let pagina;
  try {
    pagina = await esperarPagina();
  } catch (e) {
    log(`${c.rojo}${e.message}${c.reset}`);
    cerrarChrome();
    process.exit(1);
  }

  const ws = new WebSocket(pagina.webSocketDebuggerUrl);
  const cli = crearCliente(ws);

  const porRequestId = new Map();
  const capturadas = [];
  let contador = 0;

  // Escuchamos los eventos de red (mismo enfoque que watch-network.mjs).
  ws.addEventListener('message', async (ev) => {
    const msg = JSON.parse(ev.data);

    if (msg.method === 'Network.requestWillBeSent') {
      const { requestId, request, type } = msg.params;
      porRequestId.set(requestId, {
        n: ++contador, reqid: requestId, tipo: type || '',
        metodo: request.method, url: request.url,
        requestHeaders: request.headers || {}, postData: request.postData || null,
      });
    }
    if (msg.method === 'Network.responseReceived') {
      const info = porRequestId.get(msg.params.requestId);
      if (info) {
        info.status = msg.params.response.status;
        info.responseHeaders = msg.params.response.headers || {};
        if (msg.params.type) info.tipo = msg.params.type;
      }
    }
    if (msg.method === 'Network.loadingFinished') {
      const info = porRequestId.get(msg.params.requestId);
      if (!info) return;
      try {
        const cuerpo = await cli.enviar('Network.getResponseBody', { requestId: msg.params.requestId });
        info.responseBody = cuerpo?.body ?? null;
        info.responseBodyBase64 = cuerpo?.base64Encoded ?? false;
      } catch {
        info.responseBody = null;
      }
      capturadas.push(info);
      fs.appendFileSync(path.join(OUT_DIR, 'network.jsonl'), JSON.stringify(info) + '\n');
      porRequestId.delete(msg.params.requestId);
    }
  });

  // Cuando el socket este listo: activamos red, navegamos y esperamos.
  await new Promise((resolve) => ws.addEventListener('open', resolve));
  await cli.enviar('Network.enable');
  await cli.enviar('Page.enable');

  log(`Navegando a: ${TARGET_URL}`);
  await cli.enviar('Page.navigate', { url: TARGET_URL });

  log(`Capturando red durante ${SEGUNDOS_CAPTURA}s...`);
  await esperar(SEGUNDOS_CAPTURA * 1000);

  // Cookies (incl. httpOnly de sesion).
  log('Volcando cookies...');
  const { cookies = [] } = await cli.enviar('Network.getAllCookies');
  fs.writeFileSync(path.join(OUT_DIR, 'cookies.json'), JSON.stringify(cookies, null, 2));

  // Resumen de la corrida.
  const graphql = capturadas.filter((r) => r.url.includes('/api/graphql')).length;
  const resumen = {
    fecha: new Date().toISOString(),
    url: TARGET_URL,
    totalRequests: capturadas.length,
    requestsGraphql: graphql,
    totalCookies: cookies.length,
    carpeta: OUT_DIR,
  };
  fs.writeFileSync(path.join(OUT_DIR, 'resumen.json'), JSON.stringify(resumen, null, 2));

  log(`${c.verde}${c.negrita}Captura OK${c.reset} → ${OUT_DIR}`);
  log(`  requests: ${resumen.totalRequests} (graphql: ${graphql}) · cookies: ${cookies.length}`);

  // Auto-limpieza de capturas viejas (mantiene el disco bajo control).
  limpiarCapturasViejas();

  ws.close();
  cerrarChrome();
  process.exit(0);
}

// Si algo falla, igual intentamos cerrar el Chrome headless para no dejarlo colgado.
main().catch((e) => {
  log(`${c.rojo}Error: ${e.message}${c.reset}`);
  cerrarChrome();
  process.exit(1);
});
