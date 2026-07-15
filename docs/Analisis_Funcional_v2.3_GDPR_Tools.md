# Análisis Funcional — Trill AI Chat Lite v2.3.0 (GDPR Tools)

> **Estado:** Borrador para revisión. NO implementado.
> **Autor:** Generado con Claude (Cowork) para Francisco — Greensolutions Pioneers Ltd.
> **Fecha:** 2026-06-28
> **Versión objetivo del plugin:** 2.3.0 (actual en WP.org: 2.2.1)
> **Idioma del software/UI cliente:** English (UK). Este documento de trabajo está en español.
> **Épica Asana:** *Trill AI Chat — 2.0.0 OSS-first launch* (GID 1214959671876932), historias **PRV-01 … PRV-04** (sección *0. Backlog*).

---

## 1. Objetivo y propuesta de valor

La 2.3 convierte la infraestructura GDPR ya existente en Lite (que hoy vive sólo como API y ajustes) en **herramientas de cumplimiento visibles y usables para el comerciante**, sin añadir lógica nueva de tratamiento de datos. El mensaje de producto es deliberado:

> **"Herramientas GDPR completas en un plugin gratuito — y nunca almacenamos IPs."**

Eso es a la vez un objetivo funcional y el ángulo de marketing (ver §10). Cada decisión de diseño refuerza la postura *privacy-first* que diferencia a Lite de la versión Pro.

### Principios rectores
- **No reinventar lógica GDPR.** Todo se apoya en `Gdpr\ConversationManager`, que ya implementa exportación, borrado en cascada y enmascarado de email. La 2.3 es UI + auditoría + operativa, no un nuevo motor.
- **SOLID.** Cada pieza nueva tiene una única responsabilidad; las páginas admin son orquestadores finos sobre servicios existentes.
- **OWASP** (https://devguide.owasp.org/es/) en cada entrada/salida: nonce + capability + sanitización + escapado, enmascarado de PII en logs, defensa contra CSV/formula injection ya existente reutilizada.
- **Diferenciación frente a Pro:** sin columna de IP de administrador, sin tabla propia de solicitudes (se usa el WP Privacy API nativo).

---

## 2. Punto de partida — qué ya existe en el código (verificado)

Para no duplicar nada, el análisis parte de lo que ya hay en `trill-ai-chat-lite`:

| Pieza existente | Ubicación | Qué aporta a la 2.3 |
|---|---|---|
| `Gdpr\ConversationManager` | `includes/Gdpr/ConversationManager.php` | Métodos públicos `find_by_email()`, `find_by_user_id()`, `find_leads_by_email()`, `export_for_email()`, `erase_for_email()`, `delete_by_ids()`; privados `delete_cascade()`, `erase_leads_for_email()`, `mask_email()`. **Núcleo reutilizado por PRV-01/02.** |
| `Gdpr\GdprSettings` | `includes/Gdpr/GdprSettings.php` | `OPT_RETENTION_DAYS`, `RETENTION_MIN_DAYS` (7), `RETENTION_MAX_DAYS` (3650), `RETENTION_DEFAULT_DAYS` (365), `get_retention_days()`, `OPT_PRIVACY_NOTICE_URL/TEXT`, `should_render_widget_notice()`. **Base de PRV-03 y del consent gate de PRV-01.** |
| `Gdpr\PrivacyHooks` | `includes/Gdpr/PrivacyHooks.php` | Registra exporter/eraser en el WP Privacy API (`wp_privacy_personal_data_exporters/erasers`). **Lite ya integra el flujo nativo de WP; Pro no.** Reutilizado por el enlace "Request My Data" de PRV-01. |
| Cron de retención | `includes/WooCommerce/CronManager.php` (`trcl_cleanup_conversations`, usa `get_retention_days()` + `cleanup_old_conversations()`) | **Backend de PRV-03 ya operativo**; falta sólo la UI de stats/preview/run-now. |
| Pestaña Privacy | `includes/Admin/views/settings-privacy.php` | Ya configura política + retención. PRV-03 le añade tarjetas de stats y acciones. |
| Menú admin | `includes/Admin/Admin.php::add_admin_menu()` | 7 entradas bajo `trcl-chat` (Dashboard, Products, Conversations, Leads, Settings, About). **PRV-01 añade aquí el submenú GDPR Tools.** |
| Patrón admin-post seguro | `includes/Admin/Admin.php` | `check_admin_referer()` + `current_user_can('manage_trcl_chat')` + `wp_unslash`/`sanitize_*`; el manager revalida los IDs. **Patrón a replicar en todos los handlers nuevos.** |
| Migraciones | `includes/Database/Migrations.php` (`SCHEMA_VERSION = '1.4.0'`, opción `trcl_db_version`) | PRV-02 añade tabla `trcl_gdpr_audit` y sube el schema a **1.5.0**. |
| Tablas existentes | `trcl_conversations`, `trcl_messages`, `trcl_feedback`, `trcl_leads`, `trcl_analytics_events`, `trcl_content_index` | La cascada de borrado y el lookup operan sobre conversations/messages/feedback/leads. |
| Capability | `manage_trcl_chat` | Gate único de todo el área admin del plugin. |
| Saneador anti-inyección | `Conversations\CsvCellSanitizer` (v2.2) | Reutilizable si algún export de GDPR Tools genera CSV. |

**Conclusión:** la 2.3 es esencialmente *capa de presentación + auditoría + operativa* sobre servicios ya probados. Riesgo técnico bajo; riesgo de cumplimiento/UX el que hay que cuidar.

---

## 3. Alcance funcional por historia

### PRV-01 — GDPR Tools page (lookup + export + erase) + frontend de privacidad
**Est. 6–9 h. Es la historia raíz; PRV-02 depende de ella.**

#### 3.1 Lado administración (back-office)
Nueva página **Trill Chat → GDPR Tools** (`add_submenu_page`, slug `trcl-gdpr-tools`, cap `manage_trcl_chat`), con:

1. **Búsqueda por email (data subject lookup).** Un campo email + botón *Look up*. Llama a `find_by_email()` + `find_leads_by_email()` y muestra un resumen: nº de conversaciones, nº de mensajes, leads asociados (`trcl_leads`), fecha de primera/última actividad. Sin resultados → estado vacío explícito.
2. **Tres tarjetas de derechos del interesado:**
   - **Right of Access** — vista/descarga del expediente del email (envuelve `export_for_email()`, paginado).
   - **Data Portability** — exportación del mismo expediente en formato portable (JSON/CSV legible). Si se usa CSV, pasa por `CsvCellSanitizer`.
   - **Right to Erasure** — borrado total del email. **Requiere escribir literalmente `DELETE`** para confirmar (doble confirmación: typed-confirmation + nonce). Envuelve `erase_for_email()`, que ya hace la cascada feedback → messages → conversations + `erase_leads_for_email()`.
3. **Tabla de auditoría reciente** (render de PRV-02): últimas N acciones GDPR.

> **Importante:** la página es un *wrapper* de conveniencia. NO contiene SQL ni lógica de borrado propia; delega 100% en `ConversationManager`. (SRP / DIP).

#### 3.2 Lado frontend (widget) — ampliación decidida el 6-jun-2026
2. **D11 — Consent gate en el widget.** Tarjeta de aviso de privacidad que **bloquea el chat hasta que el visitante pulsa "I Understand"**. Mientras no haya consentimiento: input deshabilitado con el texto *"Please accept the privacy notice to start chatting"*. El consentimiento se persiste **en cliente** (no se crea PII en servidor por el mero hecho de mostrar el aviso). Reutiliza `GdprSettings::should_render_widget_notice()`, `get_privacy_notice_text()`, `get_privacy_notice_url()`.
3. **Enlace "Request My Data" en el footer del widget.** Crea una solicitud nativa de datos personales con `wp_create_user_request()` → dispara el email de confirmación de WP y encola la petición en *Tools → Export/Erase Personal Data*.
   - **Decisión de arquitectura (firme):** **NO** se crea tabla propia tipo `wcai_gdpr_requests` (enfoque de Pro, rechazado deliberadamente). Se usa el core de WordPress, lo que reduce superficie de ataque y mantenimiento.

#### Criterios de aceptación PRV-01
- Buscar un email existente devuelve conversaciones + mensajes + leads correctos.
- Export de acceso y de portabilidad contienen exactamente los datos del email y nada de otros sujetos.
- Erase sin escribir `DELETE` no borra nada; con `DELETE` borra en cascada y deja registro de auditoría (PRV-02).
- El widget no permite enviar mensajes hasta aceptar el aviso (si `should_render_widget_notice()` es true).
- "Request My Data" genera una solicitud visible en el panel nativo de WP y su email de confirmación.

---

### PRV-02 — GDPR audit log (sin IPs de administrador)
**Est. 2 h. Depende de PRV-01.**

- **Nueva tabla `trcl_gdpr_audit`** (migración + bump `SCHEMA_VERSION` a `1.5.0`):

  | Columna | Tipo | Notas |
  |---|---|---|
  | `id` | bigint UNSIGNED AI PK | |
  | `action` | varchar(20) | enum lógico: `search` / `view` / `export` / `erase` |
  | `target_email` | varchar(255) | **enmascarado** en el log vía `mask_email()` (p.ej. `j***@d***.com`) |
  | `performed_by` | bigint UNSIGNED | `user_id` del admin que ejecuta |
  | `records_affected` | int UNSIGNED | nº de registros tocados |
  | `created_at` | datetime DEFAULT CURRENT_TIMESTAMP | |
  | índices | `KEY idx_action`, `KEY idx_created` | |

- **Sin columna de IP del administrador.** Decisión deliberada: Pro la guarda; almacenarla rompería la postura *"we never store IPs"* de Lite, que es argumento de venta. (Consistencia de producto > telemetría.)
- Cada acción de PRV-01 (search/view/export/erase) escribe una fila. Render de las últimas acciones en una tabla dentro de GDPR Tools.
- Servicio dedicado sugerido: `Gdpr\AuditLogger` (SRP) con `log(string $action, string $email, int $records): void` y `get_recent(int $limit): array`. La página y el manager dependen de la abstracción, no de `$wpdb` directo.

#### Criterios de aceptación PRV-02
- Toda acción GDPR (incluida una búsqueda) crea exactamente una entrada.
- El email aparece enmascarado en la tabla y en cualquier log.
- No existe ninguna columna ni captura de IP.
- La migración es idempotente y respeta el patrón `dbDelta` existente.

---

### PRV-03 — Data Retention UI en la pestaña Privacy
**Est. 2–3 h. Backend ya existe.**

Sobre `settings-privacy.php` (que ya gestiona política + `trcl_retention_days`), añadir:

- **Tarjetas de estadísticas:** total de conversaciones, total de mensajes, días de retención configurados, fecha del último cleanup.
- **Preview Cleanup** — *dry-run* vía AJAX: cuenta cuántas conversaciones se eliminarían con la retención actual, **sin borrar**.
- **Run Cleanup Now** — ejecución manual con confirmación, vía AJAX, reutilizando el mismo `cleanup_old_conversations()` que usa el cron.
- **Persistencia** de `last_cleanup` (timestamp + nº borrado) en opciones, para alimentar las tarjetas.
- **Sin sección de anonimización de IP** — Lite no almacena IPs (coherencia con PRV-02).

#### Criterios de aceptación PRV-03
- Preview no modifica datos y su recuento coincide con lo que borraría Run Now.
- Run Now respeta `get_retention_days()` (con su clamp 7–3650) y actualiza `last_cleanup`.
- Endpoints AJAX protegidos con nonce + `manage_trcl_chat`.

---

### PRV-04 — QA, Plugin Check, readme + manifiesto de release SVN
**Est. 1–2 h.**

- Verificar la **cascada de borrado** completa: feedback → messages → conversations → leads.
- Verificar que **cada acción** genera entrada de auditoría.
- **Plugin Check** sobre el ZIP final (sin errores/avisos bloqueantes).
- **readme.txt / changelog** actualizados a 2.3.0 (en English UK, primera persona del singular, sin "we/our" — regla vigente del listing).
- **Manifiesto de release** para el despliegue manual a SVN (mismo procedimiento aprendido en 2.2: `svn cp` server-side con URL absoluta, `--skip-js` en `make-pot`, `.distignore` excluye `tests/`).
- Smoke tests dev-only en `tests/Gdpr/` (ya hay `GdprE2ESmoke.php`; ampliar para audit log + erase por email).

---

## 4. Modelo de datos — resumen de cambios

- **+1 tabla:** `trcl_gdpr_audit` (PRV-02).
- **Schema:** `1.4.0 → 1.5.0` en `Migrations::SCHEMA_VERSION`; nueva función `create_gdpr_audit_table()` añadida al dispatcher `run()`; `drop_tables()` debe incluir la nueva tabla en el uninstall.
- **+ Opciones nuevas:** `trcl_last_cleanup_at`, `trcl_last_cleanup_count` (PRV-03).
- **Sin cambios** en conversations/messages/feedback/leads.

---

## 5. Arquitectura propuesta (SOLID)

| Responsabilidad | Clase (nueva/existente) | Principio |
|---|---|---|
| Orquestar la página GDPR Tools (render + handlers) | `Admin\GdprToolsPage` (nueva, fina) | SRP — sólo coordina |
| Lógica de export/erase/lookup | `Gdpr\ConversationManager` (existente) | OCP/DIP — no se toca su núcleo |
| Registro de auditoría | `Gdpr\AuditLogger` (nueva) | SRP |
| Operativa de retención (preview/run) | `Gdpr\RetentionService` (nueva, envuelve `cleanup_old_conversations`) | SRP |
| Integración WP Privacy API | `Gdpr\PrivacyHooks` (existente) | reutilización |
| Saneado CSV | `Conversations\CsvCellSanitizer` (existente) | reutilización |

Las páginas admin **dependen de abstracciones** (servicios inyectables), no de `$wpdb`. Esto mantiene los handlers testeables con los smoke tests por `wp eval-file`.

---

## 6. Seguridad (OWASP — https://devguide.owasp.org/es/)

- **Control de acceso (A01):** todos los handlers y endpoints AJAX gateados con `current_user_can('manage_trcl_chat')` **y** `check_admin_referer()` / nonce. El borrado por email exige además confirmación tipeada `DELETE`.
- **Inyección (A03):** consultas siempre con `$wpdb->prepare()`; IDs revalidados en el manager; exports CSV pasan por `CsvCellSanitizer` (anti-formula injection, ya en producción).
- **Exposición de datos sensibles (A02):** email **enmascarado** (`mask_email()`) en la tabla de auditoría y en cualquier log. **Cero IPs almacenadas.**
- **Diseño (A04):** uso del WP Privacy API nativo en lugar de tabla propia → menos superficie, confirmación por email del propio core.
- **Logging/monitorización (A09):** la auditoría registra quién hizo qué y cuántos registros, sin filtrar PII en claro.
- **CSRF:** nonce en cada formulario/acción; el consent gate persiste en cliente y no abre endpoint de escritura.

---

## 7. UX y textos (English UK, cliente final)

Cadenas clave (definitivas en la implementación, aquí orientativas):
- Consent gate: *"Please accept the privacy notice to start chatting"*, botón *"I Understand"*.
- Footer widget: *"Request My Data"*.
- Erasure: *"Type DELETE to confirm permanent erasure of all data for this email."*
- Tarjetas: *"Right of Access" / "Data Portability" / "Right to Erasure"*.
- Retention: *"Preview Cleanup" / "Run Cleanup Now"*.

Todas vía `__()`/`esc_html_e()` con text-domain `trill-ai-chat-lite` y escapado en salida.

---

## 8. Estimación, dependencias y orden de trabajo

| Historia | Estimación | Depende de |
|---|---|---|
| PRV-01 GDPR Tools page + frontend | 6–9 h | — |
| PRV-02 Audit log | 2 h | PRV-01 |
| PRV-03 Retention UI | 2–3 h | — (paralelizable) |
| PRV-04 QA + release | 1–2 h | PRV-01/02/03 |
| **Total** | **~11–16 h** | |

**Orden sugerido:** PRV-02 (tabla + `AuditLogger`) y el esqueleto de PRV-01 primero, porque la auditoría debe estar lista para que PRV-01 registre desde el día uno; PRV-03 en paralelo; PRV-04 al cierre.

---

## 9. Riesgos y decisiones abiertas

- **Formato de Data Portability:** ¿JSON, CSV, o ambos? Recomendación: JSON (portabilidad real) + opción CSV legible. *Decisión pendiente de Francisco.*
- **Persistencia del consentimiento en cliente:** cookie vs `localStorage`. Recomendación: `localStorage` con clave versionada para poder forzar re-consentimiento si cambia el aviso.
- **Retención de la propia tabla de auditoría:** ¿el cleanup también poda `trcl_gdpr_audit`? Recomendación: conservarla (es el registro de cumplimiento), documentarlo.

---

## 10. Plan de anuncio — LinkedIn + Blog trillai.io

> El ángulo está alineado con el objetivo de producto (§1) y con la estrategia social vigente (Facebook + LinkedIn Company Page; patrón "link in first comment").

### 10.1 Mensaje central
**"GDPR tooling that usually sits behind a paywall — now free, and we never store IPs."** Reforzar dos pruebas concretas: (1) las tres acciones de derechos del interesado (acceso, portabilidad, borrado) integradas con el panel nativo de WordPress; (2) la decisión explícita de **no** guardar IPs como diferencia frente a la norma del sector.

### 10.2 Blog (trillai.io) — borrador de estructura
1. **Título tentativo:** *"Free GDPR tools for your WooCommerce chat — and why we deliberately don't store IPs."*
2. El problema del comerciante: peticiones de datos/borrado a mano son lentas y arriesgadas.
3. Qué trae la 2.3: GDPR Tools page (acceso/portabilidad/borrado por email), consent gate en el widget, "Request My Data" nativo, audit log, retención con preview.
4. La decisión de privacidad: por qué no almacenamos IPs (postura, no limitación).
5. Cómo se usa (capturas de la página y del widget).
6. CTA: instalar/actualizar desde WP.org.
- **SEO:** keywords *WooCommerce GDPR chat*, *GDPR data request WordPress*, *privacy-first chatbot*. Publicar primero el blog y enlazarlo desde social.

### 10.3 LinkedIn (Company Page) — borrador de post
- Hook: "Most chat plugins quietly log your visitors' IPs. We built the opposite."
- 3 bullets: derechos del interesado en un clic · consent gate antes de chatear · cero IPs.
- Cierre + CTA suave; **enlace al blog en el primer comentario** (patrón vigente).
- Acompañar de 1 captura limpia de la GDPR Tools page.

### 10.4 Coordinación de release ↔ comunicación
- El anuncio se publica **sólo cuando 2.3.0 está live y verificada** en WP.org (evitar el desfase de caché del listing visto en 2.2).
- Reutilizar la campaña de assets/reviews pendiente del listing (banner/icono/capturas) — añadir capturas nuevas de GDPR Tools y del consent gate.
- Encadenable con el blog post pendiente de v1.2.0 guardrails si se quiere una serie "privacy & safety".

> Nota: este apartado define **qué** comunicar y **cuándo**; la redacción final de blog y posts se generará como entregables aparte cuando se apruebe el funcional.

---

## 11. Definición de "Hecho" (release 2.3.0)
- PRV-01..04 cerradas con sus criterios de aceptación.
- Schema 1.5.0 migrando limpio en instalación nueva y upgrade.
- Smoke tests `tests/Gdpr/` verdes (incl. audit + erase por email).
- Plugin Check sin bloqueantes; readme/changelog en English UK (1ª persona singular).
- Paquete SVN con `.distignore` correcto; manifiesto de release listo.
- Borradores de blog (trillai.io) y LinkedIn preparados para publicar tras el go-live.
