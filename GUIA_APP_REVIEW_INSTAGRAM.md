# Guía: App Review + Verificación de negocio (Instagram business_discovery)

Objetivo: obtener **Acceso Avanzado** a `instagram_basic` (y permisos de páginas) para que
`business_discovery` pueda leer cuentas públicas ajenas (El Comercio, RPP, Infobae) desde la
app `monitoring-full`. Sin esto, en modo desarrollo Meta responde **error #10**.

> App: `monitoring-full` · IG Business: `samdevinst` (id 17841441194201451) · Página: `Monitoring-full`

---

## 0. Expectativa realista (léelo)

- **No es inmediato.** Verificación de negocio: días. App Review: días a semanas. Meta puede
  pedir cambios o rechazar y hay que reintentar.
- **Necesitas una entidad de negocio real** para la verificación (nombre legal, dirección,
  documento o dominio). Aquí ayuda que tengas el dominio **glr.pe**.
- **Necesitas un demo** (screencast) mostrando el login y el uso del permiso. Como el proyecto
  es un backend sin UI, hay que preparar algo mínimo que se pueda mostrar (ver sección 5).

---

## 1. Checklist de prerequisitos

- [ ] **Negocio/entidad real** para verificar (razón social, dirección, teléfono; o verificación
      por **dominio** `glr.pe`).
- [ ] **Política de privacidad** publicada en una URL pública (obligatoria).
- [ ] **App básica completa**: ícono, categoría, correo de contacto, dominio de la app.
- [ ] **Data Use Checkup** respondido.
- [ ] **Screencast** del flujo (login con Facebook + la app usando los datos).

---

## 2. Verificación de negocio (Business Verification)

1. Entra a **Meta Business Suite** → https://business.facebook.com/
2. Ve a **Configuración del negocio** → **Centro de seguridad** (Security Center).
3. Inicia **"Verificación del negocio"**. Te pedirá:
   - Nombre legal del negocio, país, dirección, teléfono/sitio web.
   - Confirmar por **documento** (ej. ficha RUC / constitución) **o por dominio** (`glr.pe`).
4. La verificación por **dominio** suele ser la más rápida si controlas `glr.pe`
   (agregas un registro DNS TXT o un meta-tag).
5. Conecta ese negocio verificado a la app: en el Panel de la app → **Configuración** →
   **"Verificación del negocio"** / portfolio comercial → selecciona el negocio.

---

## 3. Configurar la app antes de la revisión

En el Panel de `monitoring-full`:

1. **Configuración → Básica**:
   - **Ícono** de la app (1024×1024).
   - **Categoría**.
   - **URL de la Política de privacidad** (pública). ← si no tienes, ver nota abajo.
   - **Dominio de la app** y URL del sitio.
2. **Data Use Checkup** (te aparecerá como acción requerida): responde cómo usas cada permiso.
3. Cambia la app a **modo Live/Publicada** cuando todo esté listo (App Review lo exige).

> **Política de privacidad:** debe estar en una URL pública (puede ser una página simple en
> tu dominio, ej. `https://glr.pe/privacidad-monitoring`). Puedo ayudarte a redactar el texto.

---

## 4. Solicitar Acceso Avanzado y enviar a revisión

1. Panel de la app → **Revisión de la app** → **"Permisos y funciones"**.
2. Busca **`instagram_basic`** → clic en **"Solicitar acceso avanzado"**.
   (Repite para `pages_read_engagement`, `pages_show_list`, `business_management` si lo piden.)
3. Te pedirá una **justificación del caso de uso** y el **screencast**.

### Texto de justificación sugerido (ajústalo)
```
La app "monitoring-full" es una herramienta interna de monitoreo de medios de [GLR / tu empresa].
Usamos instagram_basic junto con el endpoint business_discovery para leer información pública de
perfil y publicaciones públicas (texto, fecha, conteo de likes y comentarios) de cuentas de
Instagram Business de medios de comunicación peruanos públicos (El Comercio, RPP, Infobae), con
fines de análisis editorial y reportería interna.

No accedemos a datos privados, no publicamos contenido ni enviamos mensajes. Solo lectura de
datos públicos de cuentas Business, consultados desde nuestra propia cuenta Business conectada a
una Página de Facebook. Los datos se almacenan internamente para tableros de analítica.
```

---

## 5. El screencast (lo más delicado)

Meta exige un video mostrando **cómo un usuario concede el permiso y cómo la app lo usa**.
Como el proyecto es un backend, prepara un demo mínimo que muestre:

1. El **inicio de sesión con Facebook** en tu app (la ventana de permisos que ya vimos, donde se
   concede acceso a la Página e Instagram).
2. La app **haciendo la llamada** `business_discovery` y **mostrando el resultado** (ej. una
   pantalla o endpoint que liste los posts públicos de El Comercio con sus métricas).
   - Sirve mostrar `/api/scrape/instagram2` devolviendo el JSON, idealmente en una vista simple.
3. Narra (o subtitula) qué permiso se usa y para qué.

> Este punto suele ser el que más rechazos genera. El reviewer necesita ver el permiso "en acción".
> Podemos armar una vista web mínima que consuma `/instagram2` para grabar el demo.

---

## 6. Tiempos y seguimiento

- Tras enviar, el estado queda en **"En revisión"**. Meta responde por notificaciones y correo.
- Si rechazan, dan un motivo → se corrige y se reenvía.
- Mientras tanto, en modo desarrollo `business_discovery` seguirá dando #10 (normal).

---

## 7. Mientras dura la revisión (plan puente)

- **YouTube**: sigue por API oficial. ✅
- **X**: sigue por scraping. ✅
- **Instagram**: mantener el scraping actual (`/api/scrape/instagram`) hasta que aprueben `instagram2`.
- **Facebook**: sigue deshabilitado (o su propio App Review + PPCA en paralelo, mismo proceso).

---

## Enlaces

- Business Suite: https://business.facebook.com/
- Panel de la app: https://developers.facebook.com/apps/1049104194430062/
- Revisión de la app / Permisos: (dentro del panel → "Revisión de la app")
- Docs business_discovery: https://developers.facebook.com/docs/instagram-api/guides/business-discovery
- Docs App Review: https://developers.facebook.com/docs/app-review
