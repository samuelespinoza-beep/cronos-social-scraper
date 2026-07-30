/**
 * request-detail.mjs
 * -----------------------------------------------------------------------------
 * Muestra el detalle completo de UNA request capturada por watch-network.mjs:
 * Headers, Payload y Response (como las pestanas de las DevTools).
 *
 * COMO FUNCIONA:
 *   watch-network.mjs va guardando cada request (con su cuerpo) en el archivo
 *   storage/network-capture.jsonl, una linea JSON por request. Este script solo
 *   abre ese archivo, busca la request por su numero #n (o su reqid) y la
 *   imprime de forma ordenada. Por eso NO necesita estar conectado a Chrome.
 *
 * USO:
 *   node scripts/request-detail.mjs 12        # por numero #n
 *   node scripts/request-detail.mjs 1361      # tambien acepta el reqid interno
 * -----------------------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';

const CAPTURE_FILE = path.resolve('storage/network-capture.jsonl');

const c = {
  reset: '\x1b[0m',
  gris: '\x1b[90m',
  verde: '\x1b[32m',
  amarillo: '\x1b[33m',
  rojo: '\x1b[31m',
  cyan: '\x1b[36m',
  negrita: '\x1b[1m',
};

// --- Validaciones de entrada ------------------------------------------------
const arg = process.argv[2];
if (!arg) {
  console.error(`${c.rojo}Falta el numero de la request.${c.reset}  Ej:  node scripts/request-detail.mjs 12`);
  process.exit(1);
}

if (!fs.existsSync(CAPTURE_FILE)) {
  console.error(
    `${c.rojo}No existe el archivo de captura.${c.reset}\n` +
    `Primero corre el watcher:  ${c.negrita}make network${c.reset}  y navega para generar requests.`
  );
  process.exit(1);
}

// --- Leemos y buscamos la request -------------------------------------------
const lineas = fs.readFileSync(CAPTURE_FILE, 'utf8').split('\n').filter(Boolean);
const registros = lineas.map((l) => JSON.parse(l));

// Buscamos por numero #n primero; si no, por reqid.
const buscado = String(arg);
const req =
  registros.find((r) => String(r.n) === buscado) ||
  registros.find((r) => String(r.reqid) === buscado);

if (!req) {
  console.error(`${c.rojo}No encontre la request #${arg}.${c.reset}  Hay ${registros.length} capturadas (de #1 a #${registros.length}).`);
  process.exit(1);
}

// --- Helpers de impresion ---------------------------------------------------
function titulo(t) {
  console.log(`\n${c.cyan}${c.negrita}--- ${t} ---${c.reset}`);
}

function imprimirHeaders(headers) {
  for (const [k, v] of Object.entries(headers || {})) {
    // Ocultamos el valor de la cookie: es tu sesion (dato sensible).
    const valor = k.toLowerCase() === 'cookie' ? `${c.rojo}[oculto por seguridad]${c.reset}` : v;
    console.log(`  ${c.gris}${k}:${c.reset} ${valor}`);
  }
}

// Intenta imprimir un texto como JSON bonito; si no es JSON, lo imprime tal cual.
function imprimirPosibleJson(texto) {
  if (!texto) {
    console.log(`  ${c.gris}(vacio)${c.reset}`);
    return;
  }
  try {
    const obj = JSON.parse(texto);
    console.log(JSON.stringify(obj, null, 2));
  } catch {
    const corto = texto.length > 4000 ? texto.slice(0, 4000) + `\n${c.gris}… (recortado)${c.reset}` : texto;
    console.log(corto);
  }
}

// --- Salida -----------------------------------------------------------------
console.log(
  `${c.amarillo}#${req.n}${c.reset}  ${c.negrita}${req.metodo}${c.reset} ` +
  `${req.status ?? '?'}  ${req.url}`
);
console.log(`${c.gris}tipo: ${req.tipo || '?'} · reqid: ${req.reqid}${c.reset}`);

titulo('REQUEST HEADERS');
imprimirHeaders(req.requestHeaders);

titulo('PAYLOAD (lo que se envia)');
if (req.postData) {
  // Si es un form (como GraphQL de Facebook), destacamos los campos clave.
  if (req.postData.includes('doc_id=') || req.postData.includes('fb_api_req_friendly_name=')) {
    const params = new URLSearchParams(req.postData);
    const destacar = ['fb_api_req_friendly_name', 'doc_id', 'variables'];
    console.log(`  ${c.cyan}Campos clave de GraphQL:${c.reset}`);
    for (const k of destacar) {
      if (params.has(k)) console.log(`  ${c.gris}${k}:${c.reset} ${params.get(k)}`);
    }
    console.log(`\n  ${c.gris}(payload completo abajo)${c.reset}`);
  }
  console.log(`  ${req.postData.length > 4000 ? req.postData.slice(0, 4000) + ' … (recortado)' : req.postData}`);
} else {
  console.log(`  ${c.gris}(sin body — normalmente es un GET)${c.reset}`);
}

titulo('RESPONSE HEADERS');
imprimirHeaders(req.responseHeaders);

titulo('RESPONSE (lo que respondio el servidor)');
if (req.responseBodyBase64) {
  console.log(`  ${c.gris}(contenido binario en base64, no se muestra)${c.reset}`);
} else {
  imprimirPosibleJson(req.responseBody);
}
console.log('');
