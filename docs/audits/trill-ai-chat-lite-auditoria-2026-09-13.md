# Trill AI Chat Lite 2.4.1 — Auditoría de seguridad y compatibilidad

Fecha: 2026-09-13
Objeto: `trill-ai-chat-lite` v2.4.1 tal como está en `Local Sites/gsplvlite` (78 ficheros PHP, ~21k líneas; 3 JS; readme.txt).
Referencia: WordPress 7.1 (estable; RC4 2026-08-17, 7.1.1 programada septiembre), WooCommerce 11.1.0 (2026-09-01), PHP 8.4.

## 0. Resumen

No hay hallazgos críticos. La base es sólida: todos los handlers de admin (11 `admin_post_*`, 6 `wp_ajax_*`) llevan nonce + `manage_trcl_chat`, todo el SQL va por `$wpdb->prepare`, la salida de admin va escapada (WPCS no marca ni un `EscapeOutput`), la respuesta del modelo se pinta como texto en el widget (sin XSS vía IA), la indexación de contenido excluye borradores/privados y el `uninstall.php` limpia tablas, opciones, transients y capabilities.

Lo que sí hay son dos problemas de nivel alto que afectan al producto en producción, cuatro medios y varios bajos. Los dos altos son:

- **A1** — el rate limit de los endpoints públicos se salta con una cabecera `X-Forwarded-For` inventada, así que cualquiera puede consumir la cuota mensual de Trill Cloud del comerciante y llenar la BD sin límite.
- **A2** — el widget envía siempre un nonce REST que se hornea en el HTML; en cualquier tienda con caché de página, a las 12–24 h ese nonce caduca y **el core de WordPress responde 403 a todos los invitados**. El chat deja de funcionar y el comerciante no ve ningún error en admin.

Ninguno de los dos requiere tocar el backend. Compatibilidad: el código es limpio en PHP 8.4 y no usa nada deprecado; lo que hay que hacer es actualizar cabeceras (`Tested up to`, `WC tested up to`) y probar el color picker por el salto a jQuery UI 1.14.2 en 7.1.

## 1. Método

1. Inventario de superficie pública y de admin (REST, AJAX, admin-post, abilities).
2. Lectura manual de: `RestController`, `ProxyClient`, `ResponseFormatter`, `DbManager`, `Frontend`, `chat-widget.js`, `Admin`, `GdprToolsPage`, `PrivacyRequestController`, `SiteVerification`, `TrialRegistration`, `TrialSecretStore`, `Encryptor`, `Logger`/`functions.php`, `OrderLookup`, `ContentIndexer`/`ContentSearch`, `ProductSearch`, `LeadCaptureService`, `AbilityRegistrar`, `uninstall.php`, cabeceras del plugin.
3. Análisis estático en contenedor con PHP 8.4.21: `php -l` de todos los ficheros; PHPCS 3.13.6 con WPCS 3.4.1 (`WordPress-Extra`), PHPCompatibilityWP 2.1.8 (`testVersion 8.0-`).
4. Contraste con las notas de desarrollo de WP 7.0/7.1, Abilities API 7.1 y las release notes de WooCommerce 11.0/11.1.

Lo que NO se ha hecho (y por qué): no se ha ejecutado el plugin en WP 7.1 (el contenedor no tiene WordPress; los smoke tests de `tests/` son `wp eval-file` y necesitan tu Local). La sección 5 describe exactamente qué ejecutar tú.

## 2. Hallazgos

Severidad: ALTO = explotable por cualquier visitante o rompe el producto en producción; MEDIO = fuga de datos o debilidad real con precondición; BAJO = higiene / defensa en profundidad.

### A1 — ALTO — El rate limit se salta falsificando la IP

`includes/AI/RestController.php:1107-1123` (`get_client_ip`) toma la IP, por este orden, de `HTTP_CLIENT_IP`, `HTTP_X_FORWARDED_FOR` y sólo al final `REMOTE_ADDR`. Las dos primeras las escribe el cliente. `enforce_rate_limit()` (líneas 801-818) usa esa IP como clave del transient.

Consecuencias, todas desde un terminal sin autenticación:

- Cada `POST /trcl/v1/message` sin `session_id` crea una conversación y llama al backend. Con una XFF distinta por petición no hay 10/min: se consume la cuota mensual (50 o 2.000) del comerciante en minutos ("denial of wallet") y el chat queda en `TRIAL_EXHAUSTED` para los clientes reales.
- Cada IP falsa crea dos filas en `wp_options` (`_transient_trcl_rate_*` + timeout) que sólo se purgan con la limpieza diaria de transients caducados: inflado de la tabla de opciones.
- El flujo de pedidos para invitados (`resolve_order_context`, ruta C) verifica `order_id + email` en cada mensaje; sin rate limit efectivo, enumerar ids de pedido contra un email conocido sólo cuesta peticiones.
- Nota: `PrivacyRequestController::check_rate_limit` (línea 74) sí usa `REMOTE_ADDR`. Dos criterios distintos en el mismo plugin.

Propuesta (un solo cambio, sin refactor):

```php
// includes/AI/RestController.php — sustituye get_client_ip()
private function get_client_ip(): string {
    $ip = isset( $_SERVER['REMOTE_ADDR'] )
        ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
        : '';

    /**
     * Sites behind a trusted reverse proxy / CDN can resolve the real
     * client IP here (e.g. from CF-Connecting-IP). Never trust
     * X-Forwarded-For by default: it is client-controlled.
     */
    $ip = (string) \apply_filters( 'trcl_client_ip', $ip );

    return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}
```

Y en el mismo cambio, que `enforce_rate_limit()` devuelva 429 cuando la IP sea `0.0.0.0` (hoy todas las peticiones sin IP válida comparten cubo, que es aceptable, pero conviene que sea explícito). El transient por IP ya caduca al minuto; con `REMOTE_ADDR` el número de cubos queda acotado por IPs reales.

### A2 — ALTO — Nonce estático en HTML cacheado: 403 para todos los invitados

- `includes/Frontend/Frontend.php:213` localiza `'nonce' => wp_create_nonce( 'wp_rest' )` en la página.
- `assets/js/chat-widget.js:467-469` envía **siempre** `X-WP-Nonce` en `POST /message`, tanto para invitados como para usuarios logueados.
- `RestController::check_message_permissions` (713-720) rechaza el nonce inválido con 403, pero da igual: el core lo hace antes (`rest_cookie_check_errors` devuelve `rest_cookie_invalid_nonce` 403 si la cabecera está presente y no valida, también para usuarios anónimos).

Un nonce de invitado vale entre 12 y 24 h. Con caché de página (WP Rocket, LiteSpeed, Cloudflare APO, hosting con caché propio: la norma en tiendas Woo), el HTML servido lleva un nonce que caduca antes que la caché. A partir de ahí todo invitado recibe 403 y el widget muestra "Sorry, I encountered an error"; el comerciante no ve nada en admin, y el dashboard sigue mostrando cuota disponible. Esto encaja con soporte del tipo "el chat funcionaba y dejó de funcionar solo".

Reproducción en 30 segundos (sin esperar 24 h): en la consola del navegador, `trcl_ajax.nonce = 'deadbeef'` y enviar un mensaje → 403 `Cookie check failed`.

Propuesta (dos ficheros):

```php
// includes/Frontend/Frontend.php (bloque $localize_data)
// Only logged-in users need the nonce: it is what lets get_current_user_id()
// resolve in the REST request (order lookup path A/B). Guests must not send
// one — a stale nonce baked into a cached page makes core answer 403.
'nonce' => \is_user_logged_in() ? \wp_create_nonce( 'wp_rest' ) : '',
```

```js
// assets/js/chat-widget.js — beforeSend del POST /message (y el mismo patrón
// en /feedback, /conversation y /privacy-request si envían la cabecera)
beforeSend: function (xhr) {
    if (trcl_ajax.nonce) {
        xhr.setRequestHeader('X-WP-Nonce', trcl_ajax.nonce);
    }
},
```

Y en `error:` del mismo `$.ajax`, si `xhr.status === 403` y `xhr.responseJSON.code === 'rest_cookie_invalid_nonce'`, reintentar una vez sin cabecera (cubre al usuario logueado cuya sesión caducó con la pestaña abierta). Como la caché de página normalmente no aplica a usuarios logueados, con esto el caso invitado queda cerrado y el logueado degrada a invitado en vez de a error.

Efecto colateral a decidir: un invitado con nonce nunca fue "logueado", así que no se pierde nada; pero conviene comprobar que `handle_message` no dependa del nonce para nada más (revisado: no).

### M1 — MEDIO — Contenido protegido por contraseña se indexa y se sirve por el chat

`includes/Content/ContentIndexer.php:152` sólo excluye `post_status !== 'publish'`. Una página con contraseña tiene `post_status = publish` y `post_password` no vacío, así que sus chunks entran en `trcl_content_index` y `ContentSearch` los inyecta en el prompt para cualquier visitante. Igual con `WP_Query` en la reindexación (línea 290): `post_status => publish` no filtra `post_password`.

Propuesta: en `index_post()`, junto al check de estado:

```php
if ( $post->post_status !== 'publish' || '' !== $post->post_password ) {
    $this->delete_for_post( $post_id );
    return false;
}
```

y en la `WP_Query` de reindexación añadir `'has_password' => false`. Los chunks ya existentes de páginas con contraseña se limpian en la siguiente reindexación completa (documentarlo en el changelog).

### M2 — MEDIO — Los logs de depuración vuelcan mensajes y emails de visitantes

Con `WP_DEBUG = true` (muy habitual en staging y, por descuido, en producción), `trcl_log()` escribe en `error_log` → `wp-content/debug.log`, con frecuencia accesible por HTTP:

- `RestController.php:306-311` y `323-327`: el texto íntegro del mensaje del visitante.
- `ContentSearch.php:211-215`: la consulta normalizada (mismo texto).
- Los mensajes contienen emails y números de pedido por diseño (lead capture y order tracking).

Además `trcl_log()` (`includes/functions.php`) ignora `WP_DEBUG_LOG` y `TRCL_LOG_LEVEL`, que sí respeta la clase `Utils\Logger` (que nadie instancia). Hay dos loggers y el que se usa es el menos seguro.

Propuesta: (1) no registrar nunca el contenido: sustituir `'message' => $message` por `'message_len' => mb_strlen( $message )` en las tres llamadas; (2) en `trcl_log()`, salir también si `! WP_DEBUG_LOG` y respetar `TRCL_LOG_LEVEL` con la misma tabla de niveles que `Logger` (o hacer que `trcl_log()` delegue en `Logger`, que es el cambio más pequeño que unifica). Esto también encaja con lo que promete la política de privacidad de Trill Cloud (sin IPs, 90 días).

### M3 — MEDIO — El secreto de Trill Cloud se guarda en claro

`includes/Lite/TrialSecretStore.php` guarda `tt_trial_*` en `wp_options` en texto plano (`autoload = no`, eso está bien). Existe `Utils\Encryptor` (AES-256-CBC con clave derivada de `AUTH_KEY`) y no se usa en ningún sitio. Quien lea la BD (backup filtrado, SQLi de otro plugin, phpMyAdmin compartido) puede consumir la cuota del comerciante desde fuera.

Propuesta: `set_secret()` guarda `Encryptor::encrypt( $secret )`; `get_secret()` descifra y, si `decrypt()` devuelve `false` (por ejemplo, el hosting rotó `AUTH_KEY`), devuelve `''` para que el flujo existente de `AUTH_INVALID` → `clear_secret()` → rotación se encargue solo. Añadir migración: si el valor almacenado no descifra pero empieza por `tt_trial_`, es un secreto legado en claro: cifrarlo y reescribirlo. Bajo riesgo, un fichero, y no toca el backend.

### M4 — MEDIO — La ventana de verificación de rotación es legible por terceros (requiere backend)

`SiteVerification` publica el token en `GET /wp-json/trcl/v1/verify` durante 5 min (`permission_callback => __return_true`). El backend lo lee para autorizar `POST /v1/trial/rotate`. Pero cualquiera que sondee ese endpoint en la ventana obtiene el token y puede llamar él mismo a `rotate` con `siteUrl + verifyToken`: el backend verifica contra el sitio (coincide) y **le entrega el secreto nuevo al atacante**; el plugin legítimo se queda con un secreto inválido o con 409. La ventana es corta y sólo se abre tras un 409 en activación, así que es MEDIO y no ALTO, pero el diseño es invertible.

Propuesta (para `trill-cloud-backend`, no para el plugin): que `rotate` sea en dos pasos: el backend genera un `challenge` aleatorio y lo devuelve; el plugin publica en `/verify` `hash_hmac( 'sha256', $challenge, $token )` y NO el token; el backend compara. Quien lea `/verify` sólo ve un HMAC inservible sin el `challenge`, que sólo conoce el llamante original. En el plugin el cambio es que `handle_verify` sirve el HMAC. Lo dejo como decisión (D7) porque cruza al otro repositorio.

### B1 — BAJO — `/feedback` no prueba propiedad de sesión ni existencia del mensaje

`RestController::handle_feedback` (665-695) y `DbManager::save_feedback` insertan `message_id, rating, comment` sin comprobar que `message_id` exista ni que pertenezca a la sesión del llamante. Cualquiera puede valorar cualquier mensaje o inflar la tabla. Propuesta: exigir `session_id` en el body y comprobar `message_id ∈ mensajes de esa conversación` antes de insertar.

### B2 — BAJO — `GET /conversation/{uuid}` y la ability `get-conversation-summary`: el UUID es el bearer

Es un diseño aceptable (UUID v4 de `wp_generate_uuid4`, no enumerable), pero los comentarios de `check_conversation_permissions` y del `AbilityRegistrar` dicen "validates ownership via cookie/fingerprint" y no es así. Propuesta: corregir los comentarios y añadir rate limit por IP también aquí (ya lo tiene, 30/min, pero con A1 sin arreglar es papel mojado). No exponer nunca esa ability con `meta.public`/`show_in_rest` mientras la autorización sea sólo el UUID.

### B3 — BAJO — Se recomiendan productos con visibilidad "hidden"

`includes/Search/ProductSearch.php:66-70` y la búsqueda por taxonomía usan `'status' => 'publish'` sin `visibility`. Un producto oculto del catálogo (típico: productos internos, packs para B2B) aparece en las tarjetas del chat. Propuesta: añadir `'visibility' => 'visible'` (o `'catalog'`) a todas las llamadas `wc_get_products` del fichero.

### B4 — BAJO — Fatal potencial en el detector de conflicto legado

`trill-ai-chat-lite.php:84-95`: si `WCAI_VERSION` está definido, se llama a `deactivate_plugins()` también en front, donde esa función no está cargada (`wp-admin/includes/plugin.php`) → fatal. El segundo bloque (97-104) sí comprueba `function_exists( 'is_plugin_active' )`. Como ya no existe versión de pago del plugin (Trill Cloud es un servicio, no un plugin), propuesta: eliminar los dos bloques de conflicto (líneas 81-104). Si prefieres conservarlo, envolver con `if ( function_exists( 'deactivate_plugins' ) )`.

### B5 — BAJO — Atributos sin escapar en las tarjetas de producto

`assets/js/chat-widget.js:580-590`: `product.image`, `product.url` y `product.id` se concatenan en atributos HTML sin escapar (el nombre sí). Los datos son del comerciante (permalink e imagen de WC), así que no es explotable por visitantes, pero el fichero ya tiene un helper de escape (línea ~1022): usarlo también aquí. `price_html` viene de `get_price_html()`, HTML legítimo de Woo, se deja.

### B6 — BAJO — `Encryptor` degrada a base64 si falta OpenSSL

`includes/Utils/Encryptor.php`: sin `openssl` devuelve base64 con un aviso en log. Si aplicas M3, cambia el fallback a devolver `false` y guardar en claro con aviso de admin, en vez de "cifrado" ficticio. OpenSSL viene en prácticamente todo hosting PHP 8, así que es defensivo.

## 3. Compatibilidad WordPress 7.1 / WooCommerce 11.1 / PHP 8.4

| Ítem | Estado | Acción |
|---|---|---|
| `php -l` en PHP 8.4.21, 64 ficheros | Sin errores | — |
| PHPCompatibilityWP (`testVersion 8.0-`) | 0 hallazgos. Limitación: la 9.3.5 estable no cubre las deprecaciones de 8.4; revisadas a mano las habituales (nullables implícitos, `E_STRICT`, `${var}`): todos los `= null` ya llevan `?Tipo`. | — |
| WPCS `WordPress-Extra`: `Security.*`, `DB.*`, `WP.DeprecatedFunctions/Parameters/Classes` (`minimum_wp_version 6.0`) | 0 hallazgos reales. 1 aviso de nonce en `GdprToolsPage.php:338` es falso positivo (el `check_admin_referer` está en cada llamador). 6 avisos "no valid placeholders" son los `IN (%d,%d…)` dinámicos, correctos. | Opcional: añadir `manage_trcl_chat` a `custom_capabilities` en un `phpcs.xml` para silenciar los 28 "Capabilities unknown". |
| Estilo WPCS | ~14.900 avisos de formato (indentación con espacios, `[]` en vez de `array()`). No afecta a WP.org ni a Plugin Check. | No tocar salvo que quieras adoptar tabs; sería un commit sólo de formato. |
| i18n just-in-time (WP 6.7+) | Ninguna llamada a `__()` en constructores/`init`/`register_hooks` ejecutados en `plugins_loaded`. | — |
| `readme.txt` `Tested up to: 7.0` | Desfasado. | Subir a `7.1` tras las pruebas de la sección 5. |
| Cabecera `WC tested up to: 9.5` | Desfasada dos majors (WC 11.1.0 desde 2026-09-01). WooCommerce muestra "no probado con tu versión" en Plugins. | Subir a `11.1` tras probar. `WC requires at least: 8.0` puede quedarse. |
| HPOS | `declare_compatibility( 'custom_order_tables' )` presente; `wc_get_orders`/`wc_get_order` en `OrderLookup`. | — |
| WooCommerce 11.1: bloques no se registran en peticiones REST/Store API por defecto | El plugin no renderiza bloques de Woo en REST. `get_price_html()` en `ResponseFormatter` no depende de bloques. | Verificar en smoke que las tarjetas siguen mostrando precio. |
| Abilities API (WP 6.9 → 7.1) | Registro con `wp_abilities_api_init`/`_categories_init` correcto. El comentario de `AbilityRegistrar.php:180-184` está desfasado: en 7.1 la exposición se controla con `meta.public` (por defecto `false`) y `meta.show_in_rest` sigue siendo autoritativo; sin ninguno de los dos las abilities no salen por REST, que es lo que hay hoy. | Actualizar el comentario. Si algún día se expone `get-products`/`get-store-context` con `public => true`, dejar fuera `get-conversation-summary` (B2). |
| WP 7.1: jQuery UI 1.14.2 | La página Apariencia usa `wp-color-picker` (Iris, depende de jQuery UI). | Probar los color pickers en 7.1 (sección 5). |
| WP 7.1: editor en iframe universal | El plugin no registra bloques ni metaboxes. | — |
| WP 7.1: `_wp_personal_data_cleanup_requests()` pasa a cron | Afecta a las solicitudes nativas que crea `PrivacyRequestController`; comportamiento de core, sin cambios necesarios. | — |
| `Requires Plugins: woocommerce` + `Requires at least: 6.0` | La cabecera se ignora en < 6.5 y el check en `plugins_loaded` cubre ese caso. | — |

## 4. Guía de aplicación (cuando decidamos encararlo)

Orden recomendado, cada punto un commit:

1. A1 `get_client_ip()` + filtro `trcl_client_ip`.
2. A2 nonce sólo para logueados + cabecera condicional + reintento en 403.
3. M1 `post_password` / `has_password`.
4. M2 quitar contenido de los logs + `trcl_log()` respeta `WP_DEBUG_LOG`/`TRCL_LOG_LEVEL`.
5. B3, B4, B5, B1 (pequeños, sin dependencias).
6. M3 cifrado del secreto (último de los del plugin, porque toca el flujo de registro y quiero verlo aislado en un smoke).
7. Cabeceras `Tested up to: 7.1` y `WC tested up to: 11.1`, changelog, bump a 2.4.2 (o 2.5.0 si entra M3).
8. M4 en `trill-cloud-backend`, en sesión aparte.

`yarn build` para regenerar `chat-widget.min.js`, y no olvidar `.pot` si cambia alguna cadena (A2 no cambia ninguna).

## 5. Qué probar en `gsplvlite` (WP 7.1)

Estado actual (antes de cambios) — sirve para confirmar los hallazgos:

- A1: `for i in $(seq 1 15); do curl -s -o /dev/null -w "%{http_code}\n" -X POST "$SITE/wp-json/trcl/v1/message" -H "Content-Type: application/json" -H "X-Forwarded-For: 10.0.0.$i" -d '{"message":"hola"}'; done` → hoy ninguna devuelve 429. Cada una crea una conversación (verlo en Conversations).
- A2: consola del navegador como invitado, `trcl_ajax.nonce='deadbeef'`, enviar mensaje → 403 `rest_cookie_invalid_nonce`.
- M1: crear página con contraseña con un texto único, activarla en Settings → Content, reindexar, preguntar al chat por ese texto → aparece.
- M2: `WP_DEBUG` + `WP_DEBUG_LOG` en `wp-config.php`, enviar "mi email es test@example.com" → el email aparece en `debug.log`.
- B3: producto con visibilidad "Hidden", preguntar por él → sale en tarjetas.

Después de aplicar los cambios:

- Caso feliz: invitado envía 3 mensajes seguidos (sin nonce), obtiene respuesta y tarjetas de producto con precio; usuario logueado con pedido pregunta "where is my order" → ve su pedido (el nonce sigue llegando en su caso).
- Bordes: (a) invitado con `X-Forwarded-For` variable → 429 a la 11ª; (b) logueado con nonce caducado (cambiar `trcl_ajax.nonce`) → primer intento 403, reintento sin nonce responde como invitado; (c) página con contraseña reindexada → no aparece; (d) desactivar y reactivar el plugin con secreto cifrado → registro/rotación sin `AUTH_INVALID`; (e) `wp option get trcl_trial_secret` no empieza por `tt_trial_`.
- Smoke tests del repo: `wp eval-file tests/Gdpr/GdprE2ESmoke.php` y el resto de `tests/**` (todos llevan `Usage:` en cabecera).
- Plugin Check (plugin oficial de WP.org) sobre el zip generado con `.distignore`: debe salir limpio de `security`/`plugin_repo`.
- Apariencia → color pickers (jQuery UI 1.14.2): abrir, arrastrar, guardar.
- Consola PHP (`debug.log`) sin deprecations durante todo lo anterior en PHP 8.4.

## 6. Decisiones abiertas

- **D4 — Estrategia de IP para el rate limit.** (a) `REMOTE_ADDR` + filtro `trcl_client_ip` (recomendada: segura por defecto, un hook para Cloudflare/proxies, cero configuración en admin); (b) leer `X-Forwarded-For` sólo si `REMOTE_ADDR` está en una lista de proxies de confianza configurable en Settings (más correcto, pero añade UI y opción nuevas).
- **D5 — Nonce para invitados.** (a) no enviar nonce a invitados + reintento en 403 (recomendada: sin peticiones extra, resuelve el 100 % del caso caché); (b) pedir un nonce fresco vía `admin-ajax.php?action=rest-nonce` antes de cada envío (una petición más por mensaje y `admin-ajax` suele estar bloqueado por bots/WAF).
- **D6 — Cifrar el secreto (M3).** (a) sí, con `Encryptor` y migración transparente (recomendada); (b) dejarlo en claro y documentarlo. La rotación de `AUTH_KEY` está cubierta por el flujo `AUTH_INVALID` existente.
- **D7 — Endurecer la rotación (M4).** (a) planificar el cambio en `trill-cloud-backend` (challenge + HMAC) y hacer el del plugin en la misma release (recomendada, sin prisa: ventana corta y rara); (b) aceptar el riesgo y documentarlo.
- **D8 — Logs (M2).** (a) que `trcl_log()` delegue en `Utils\Logger` y eliminar el segundo camino (recomendada: un solo logger, niveles reales); (b) sólo quitar el contenido de las tres llamadas.
- **D9 — Versión.** (a) 2.4.2 con A1, A2, M1, M2 y los bajos, y 2.5.0 después con M3/M4 (recomendada: lo urgente sale antes y es fácil de revisar); (b) todo en 2.5.0.

## 7. Fuentes

- WordPress 7.1: https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/ · https://make.wordpress.org/core/2026/08/17/wordpress-7-1-release-candidate-4/ · https://make.wordpress.org/core/2026/09/02/wordpress-7-1-1-release-schedule/
- WordPress 7.0: https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/
- Abilities API 7.1: https://make.wordpress.org/core/2026/08/04/a-unified-public-exposure-flag-for-abilities-in-wordpress-7-1/ · https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/
- WooCommerce: https://developer.woocommerce.com/2026/09/03/wc-11-1-release-notes/ · https://developer.woocommerce.com/2026/08/04/woocommerce-11-0/
- Herramientas: PHP 8.4.21, PHP_CodeSniffer 3.13.6, WPCS 3.4.1, PHPCompatibilityWP 2.1.8, woocommerce-sniffs 2.0.0.
