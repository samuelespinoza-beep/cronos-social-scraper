/**
 * watch-network.mjs
 * -----------------------------------------------------------------------------
 * Escucha EN VIVO todas las peticiones de red (requests) que hace la ventana
 * de Chrome de depuracion y las imprime aqui en la terminal.
 *
 * ADEMAS: guarda el detalle completo de cada request (headers, payload y
 * response) en un archivo (storage/network-capture.jsonl) para que luego el
 * comando "request-detail.mjs" pueda mostrartelo sin tener que estar conectado.
 *
 * COMO FUNCIONA (para aprender):
 *
 *   1. Chrome, cuando se abre con --remote-debugging-port=9222, expone un
 *      "Chrome DevTools Protocol" (CDP). Es la MISMA tuberia que usa la pestana
 *      Network de las DevTools. Nosotros nos conectamos a esa tuberia.
 *
 *   2. Primero preguntamos por HTTP a http://localhost:9222/json cuales pestanas
 *      hay abiertas. Cada pestana tiene una "webSocketDebuggerUrl".
 *
 *   3. Abrimos un WebSocket a esa URL y le hablamos a Chrome enviando comandos
 *      en JSON. Le decimos "Network.enable" para que empiece a avisarnos.
 *
 *   4. Chrome nos responde con "eventos". Escuchamos tres:
 *        - Network.requestWillBeSent  -> sale una peticion (trae headers y body)
 *        - Network.responseReceived   -> llega la respuesta (trae status/headers)
 *        - Network.loadingFinished    -> ya termino de descargar; recien aqui
 *                                        podemos pedir el cuerpo de la respuesta
 *                                        con el comando Network.getResponseBody.
 *
 * NO necesita instalar nada: Node 22+ ya trae fetch y WebSocket globales.
 *
 * USO:
 *   1) Abre la ventana de depuracion:   make chrome
 *   2) Corre este script:               node scripts/watch-network.mjs
 *   3) Navega en esa ventana y mira como aparecen las requests (cada una con
 *      un numero #n al inicio).
 *   4) Para ver el detalle de una:      node scripts/request-detail.mjs 12
 *   5) Corta con Ctrl+C.
 *
 * FILTRO opcional: pasa un texto para ver solo las que lo contengan. Ej:
 *   node scripts/watch-network.mjs graphql
 * -----------------------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';

// Puerto donde escucha el Chrome de depuracion (el mismo del make chrome).
const PORT = 9222;

// Filtro opcional: primer argumento de la linea de comandos.
const filtro = process.argv[2] || null;

// Archivo donde guardamos el detalle de cada request (una linea JSON por request).
// request-detail.mjs leera de aqui.
const CAPTURE_FILE = path.resolve('storage/network-capture.jsonl');

// Colores ANSI para que la terminal se lea bonito (sin librerias).
const c = {
  reset: '\x1b[0m',
  gris: '\x1b[90m',
  verde: '\x1b[32m',
  amarillo: '\x1b[33m',
  rojo: '\x1b[31m',
  cyan: '\x1b[36m',
  negrita: '\x1b[1m',
};

function colorStatus(status) {
  if (status >= 200 && status < 300) return c.verde + status + c.reset;
  if (status >= 300 && status < 400) return c.amarillo + status + c.reset;
  if (status >= 400) return c.rojo + status + c.reset;
  return c.gris + status + c.reset;
}

// --- Paso 1: pedir a Chrome la lista de pestanas abiertas -------------------
async function obtenerPaginaObjetivo() {
  let lista;
  try {
    const resp = await fetch(`http://localhost:${PORT}/json`);
    lista = await resp.json();
  } catch (e) {
    console.error(
      `${c.rojo}No pude conectar al puerto ${PORT}.${c.reset}\n` +
      `Asegurate de haber abierto la ventana de depuracion con:  ${c.negrita}make chrome${c.reset}`
    );
    process.exit(1);
  }

  // Solo pestanas web reales (http/https). Descartamos internas (devtools://...).
  const paginas = lista.filter(
    (t) =>
      t.type === 'page' &&
      t.webSocketDebuggerUrl &&
      /^https?:\/\//.test(t.url)
  );
  if (paginas.length === 0) {
    console.error(`${c.rojo}No hay ninguna pestana abierta en el Chrome de depuracion.${c.reset}`);
    process.exit(1);
  }

  const pagina = paginas[0];
  console.log(`${c.cyan}Escuchando la red de:${c.reset} ${c.negrita}${pagina.title}${c.reset}`);
  console.log(`${c.gris}${pagina.url}${c.reset}`);
  if (filtro) console.log(`${c.cyan}Filtro activo:${c.reset} solo requests que contengan "${filtro}"`);
  console.log(`${c.gris}Detalle guardandose en: ${CAPTURE_FILE}${c.reset}`);
  console.log(`${c.gris}(Ctrl+C para salir · usa el numero #n con: node scripts/request-detail.mjs <n>)${c.reset}\n`);
  return pagina;
}

// Estado que PERSISTE entre reconexiones:
//  - contador: el numero #n que ve el usuario (sigue creciendo aunque reconecte)
let contador = 0;

// --- Conexion (se puede volver a llamar si el socket se cae por navegar) -----
function conectar(webSocketDebuggerUrl) {
  const ws = new WebSocket(webSocketDebuggerUrl);

  // Maquinaria para enviar comandos y ESPERAR su respuesta. CDP exige un id
  // incremental; guardamos una promesa por id (lo usamos con getResponseBody).
  let idComando = 1;
  const comandosPendientes = new Map();
  function enviarEsperar(metodo, params = {}) {
    const id = idComando++;
    ws.send(JSON.stringify({ id, method: metodo, params }));
    return new Promise((resolve) => comandosPendientes.set(id, resolve));
  }

  // Piezas de cada request en curso (se reinicia en cada conexion porque los
  // requestId son propios de cada sesion de Chrome).
  const porRequestId = new Map();

  ws.addEventListener('open', () => {
    enviarEsperar('Network.enable');
  });

  ws.addEventListener('message', async (evento) => {
    const msg = JSON.parse(evento.data);

    // (a) Respuesta a un comando nuestro (tiene "id"): resolvemos su promesa.
    if (msg.id && comandosPendientes.has(msg.id)) {
      comandosPendientes.get(msg.id)(msg.result);
      comandosPendientes.delete(msg.id);
      return;
    }

    // (b) Sale una peticion. Guardamos headers y body de la peticion.
    if (msg.method === 'Network.requestWillBeSent') {
      const { requestId, request, type } = msg.params;
      porRequestId.set(requestId, {
        n: ++contador,
        reqid: requestId,
        tipo: type || '',
        metodo: request.method,
        url: request.url,
        requestHeaders: request.headers || {},
        postData: request.postData || null, // el "Payload"
      });
    }

    // (c) Llega la respuesta. Guardamos status y headers de respuesta.
    if (msg.method === 'Network.responseReceived') {
      const { requestId, response, type } = msg.params;
      const info = porRequestId.get(requestId);
      if (!info) return;
      info.status = response.status;
      info.responseHeaders = response.headers || {};
      if (type) info.tipo = type;

      // Imprimimos la linea en vivo (con el numero #n al inicio).
      if (!filtro || info.url.includes(filtro)) {
        const urlCorta = info.url.length > 110 ? info.url.slice(0, 110) + '…' : info.url;
        console.log(
          `${c.amarillo}#${String(info.n).padEnd(4)}${c.reset}` +
          `${c.gris}[${info.tipo.padEnd(8)}]${c.reset} ` +
          `${c.negrita}${info.metodo.padEnd(4)}${c.reset} ` +
          `${colorStatus(info.status)} ` +
          `${urlCorta}`
        );
      }
    }

    // (d) Termino de descargar. Recien aqui pedimos el CUERPO de la respuesta y
    //     guardamos todo el detalle en el archivo.
    if (msg.method === 'Network.loadingFinished') {
      const { requestId } = msg.params;
      const info = porRequestId.get(requestId);
      if (!info) return;
      try {
        const cuerpo = await enviarEsperar('Network.getResponseBody', { requestId });
        info.responseBody = cuerpo?.body ?? null;
        info.responseBodyBase64 = cuerpo?.base64Encoded ?? false;
      } catch {
        info.responseBody = null;
      }
      fs.appendFileSync(CAPTURE_FILE, JSON.stringify(info) + '\n');
      porRequestId.delete(requestId);
    }
  });

  // Silenciamos el error: casi siempre viene junto con el "close" al navegar.
  ws.addEventListener('error', () => {});

  // Al cerrarse (por ejemplo al navegar a otra pagina), reconectamos solos.
  ws.addEventListener('close', () => {
    reconectar();
  });
}

// --- Reconexion: busca de nuevo la pestana activa y se vuelve a enganchar ----
let reconectando = false;
async function reconectar() {
  if (reconectando) return;
  reconectando = true;
  // Reintentamos hasta que Chrome nos vuelva a dar una pestana valida.
  for (let intento = 0; intento < 40; intento++) {
    try {
      const resp = await fetch(`http://localhost:${PORT}/json`);
      const lista = await resp.json();
      const pagina = lista.find(
        (t) => t.type === 'page' && t.webSocketDebuggerUrl && /^https?:\/\//.test(t.url)
      );
      if (pagina) {
        console.log(`${c.gris}(reconectado a: ${pagina.url.slice(0, 70)})${c.reset}`);
        reconectando = false;
        conectar(pagina.webSocketDebuggerUrl);
        return;
      }
    } catch {
      // Si el puerto ya no responde, Chrome se cerro de verdad -> salimos.
      console.log(`\n${c.amarillo}El Chrome de depuracion se cerro. Fin.${c.reset}`);
      process.exit(0);
    }
    await new Promise((r) => setTimeout(r, 500)); // esperamos medio segundo y reintentamos
  }
  console.log(`\n${c.amarillo}No pude reconectar. Fin.${c.reset}`);
  process.exit(0);
}

// --- Arranque ---------------------------------------------------------------
async function main() {
  const pagina = await obtenerPaginaObjetivo();

  // Empezamos el archivo de captura desde cero SOLO al arrancar (no al reconectar),
  // para no mezclar requests de sesiones viejas que ya no se pueden consultar.
  fs.mkdirSync(path.dirname(CAPTURE_FILE), { recursive: true });
  fs.writeFileSync(CAPTURE_FILE, '');

  conectar(pagina.webSocketDebuggerUrl);
}

main();
