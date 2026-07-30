/**
 * cookies-dump.mjs
 * -----------------------------------------------------------------------------
 * Muestra en la terminal las COOKIES de la pestana activa (lo que verias en
 * DevTools -> Application -> Cookies), INCLUIDAS las httpOnly (las de sesion,
 * que NO se pueden leer con JavaScript / document.cookie).
 *
 * COMO FUNCIONA:
 *   Nos conectamos al Chrome de depuracion por CDP (igual que watch-network) y
 *   usamos el comando "Network.getAllCookies", que devuelve TODAS las cookies
 *   del navegador con todos sus campos (name, value, domain, httpOnly, etc.).
 *   Luego filtramos por el dominio del sitio que tengas abierto.
 *
 * USO:
 *   node scripts/cookies-dump.mjs            # cookies del sitio abierto
 *   node scripts/cookies-dump.mjs instagram  # filtra por un dominio distinto
 *   node scripts/cookies-dump.mjs all        # TODAS las cookies del navegador
 *
 * SEGURIDAD:  las cookies de sesion (c_user, xs, fr, datr...) equivalen a tu
 * login. El archivo storage/cookies-dump.json esta en .gitignore. No lo
 * compartas ni lo subas a ningun lado.
 * -----------------------------------------------------------------------------
 */

import fs from 'node:fs';
import path from 'node:path';

const PORT = 9222;
const OUT_FILE = path.resolve('storage/cookies-dump.json');
const filtroArg = process.argv[2] || null; // dominio a filtrar, o "all"

const c = {
  reset: '\x1b[0m', gris: '\x1b[90m', verde: '\x1b[32m', amarillo: '\x1b[33m',
  rojo: '\x1b[31m', cyan: '\x1b[36m', negrita: '\x1b[1m',
};

// --- Paso 1: ubicar la pestana activa y su dominio --------------------------
async function obtenerPagina() {
  let lista;
  try {
    lista = await (await fetch(`http://localhost:${PORT}/json`)).json();
  } catch {
    console.error(`${c.rojo}No pude conectar al puerto ${PORT}.${c.reset}  Abre la ventana con:  ${c.negrita}make chrome${c.reset}`);
    process.exit(1);
  }
  const pagina = lista.find((t) => t.type === 'page' && t.webSocketDebuggerUrl && /^https?:\/\//.test(t.url));
  if (!pagina) {
    console.error(`${c.rojo}No hay ninguna pestana web abierta en el Chrome de depuracion.${c.reset}`);
    process.exit(1);
  }
  return pagina;
}

// Extrae el "dominio registrable" de una url. Ej: www.facebook.com -> facebook.com
function dominioBase(url) {
  try {
    const host = new URL(url).hostname;          // www.facebook.com
    const partes = host.split('.');
    return partes.slice(-2).join('.');           // facebook.com
  } catch {
    return '';
  }
}

// --- Formato de fecha de expiracion -----------------------------------------
function formatExpira(cookie) {
  if (cookie.session) return 'sesion'; // se borra al cerrar el navegador
  if (!cookie.expires || cookie.expires < 0) return '-';
  // expires viene en segundos (epoch). Lo pasamos a fecha legible.
  return new Date(cookie.expires * 1000).toISOString().slice(0, 10);
}

async function main() {
  const pagina = await obtenerPagina();
  const base = dominioBase(pagina.url);

  const ws = new WebSocket(pagina.webSocketDebuggerUrl);

  // Mini-cliente CDP: enviar un comando y esperar su respuesta.
  let idComando = 1;
  const pendientes = new Map();
  function enviarEsperar(metodo, params = {}) {
    const id = idComando++;
    ws.send(JSON.stringify({ id, method: metodo, params }));
    return new Promise((resolve) => pendientes.set(id, resolve));
  }

  ws.addEventListener('message', (evento) => {
    const msg = JSON.parse(evento.data);
    if (msg.id && pendientes.has(msg.id)) {
      pendientes.get(msg.id)(msg.result);
      pendientes.delete(msg.id);
    }
  });

  ws.addEventListener('open', async () => {
    // Pedimos TODAS las cookies del navegador.
    const res = await enviarEsperar('Network.getAllCookies');
    let cookies = res?.cookies || [];

    // Filtramos por dominio (salvo que pidan "all").
    let etiquetaFiltro;
    if (filtroArg === 'all') {
      etiquetaFiltro = 'todas';
    } else {
      const objetivo = filtroArg || base; // por defecto, el sitio abierto
      cookies = cookies.filter((ck) => (ck.domain || '').includes(objetivo));
      etiquetaFiltro = objetivo;
    }

    // Ordenamos por nombre para que sea comodo de leer.
    cookies.sort((a, b) => a.name.localeCompare(b.name));

    // --- Encabezado ---
    console.log(`${c.cyan}Cookies de:${c.reset} ${c.negrita}${etiquetaFiltro}${c.reset}  ${c.gris}(pestana: ${pagina.url.slice(0, 60)})${c.reset}`);
    console.log(`${c.rojo}${c.negrita}⚠  Contienen tu sesion. No compartas este archivo ni estos valores.${c.reset}\n`);

    if (cookies.length === 0) {
      console.log(`${c.amarillo}No se encontraron cookies para "${etiquetaFiltro}".${c.reset}`);
    }

    // --- Tabla (como Application -> Cookies) ---
    for (const ck of cookies) {
      const flags = [
        ck.httpOnly ? 'HttpOnly' : '',
        ck.secure ? 'Secure' : '',
        ck.sameSite ? `SameSite=${ck.sameSite}` : '',
      ].filter(Boolean).join(' ');

      // El valor puede ser largo: lo mostramos completo (es lo que pediste ver),
      // pero marcamos en rojo las cookies de sesion mas sensibles.
      const sensible = ['xs', 'fr', 'c_user', 'datr', 'sb'].includes(ck.name);
      const nombreColor = sensible ? c.rojo : c.verde;

      console.log(`${nombreColor}${c.negrita}${ck.name}${c.reset}`);
      console.log(`  ${c.gris}valor:${c.reset}   ${ck.value}`);
      console.log(`  ${c.gris}dominio:${c.reset} ${ck.domain}   ${c.gris}path:${c.reset} ${ck.path}`);
      console.log(`  ${c.gris}expira:${c.reset}  ${formatExpira(ck)}   ${c.gris}${flags}${c.reset}`);
      console.log('');
    }

    // --- Guardamos el JSON completo para uso posterior (gitignored) ---
    fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });
    fs.writeFileSync(OUT_FILE, JSON.stringify(cookies, null, 2));
    console.log(`${c.gris}${cookies.length} cookies · guardadas en ${OUT_FILE}${c.reset}`);

    ws.close();
    process.exit(0);
  });

  ws.addEventListener('error', () => {
    console.error(`${c.rojo}Error de conexion al Chrome de depuracion.${c.reset}`);
    process.exit(1);
  });
}

main();
