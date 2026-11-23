<p align="center"><img src="docs/logo.svg" alt="Clinical Trial Watcher" width="128"></p>

<h1 align="center">Clinical Trial Watcher</h1>

<h3 align="center">Importa estudios clínicos de ClinicalTrials.gov, permite buscarlos y envía un correo cuando los estudios que sigue se abren, cambian o empiezan a reclutar.</h3>

<p align="center">
  <a href="README.md">English</a> · <a href="README.pt-BR.md">Português (Brasil)</a> · <a href="README.es.md">Español</a> · <a href="README.zh-CN.md">简体中文</a> · <a href="README.zh-TW.md">繁體中文</a> · <a href="README.ja.md">日本語</a> · <a href="README.ko.md">한국어</a>
</p>

<p align="center">
  <img alt="Status: early development" src="https://img.shields.io/badge/status-early_development-orange?style=flat-square">
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/ci.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/ci.yml?branch=main&label=tests&logo=githubactions&logoColor=white&style=flat-square"></a>
  <a href="https://github.com/czarist/clinical-trial-watcher/actions/workflows/packages.yml"><img alt="Packages audit" src="https://img.shields.io/github/actions/workflow/status/czarist/clinical-trial-watcher/packages.yml?branch=main&label=packages&logo=dependabot&logoColor=white&style=flat-square"></a>
  <img alt="PHP 8.6" src="https://img.shields.io/badge/PHP-8.6-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white">
  <img alt="TypeScript 7" src="https://img.shields.io/badge/TypeScript-7-3178C6?style=flat-square&logo=typescript&logoColor=white">
  <img alt="MySQL 8.4" src="https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql&logoColor=white">
  <img alt="Redis 8.10" src="https://img.shields.io/badge/Redis-8.10-FF4438?style=flat-square&logo=redis&logoColor=white">
  <img alt="Stripe" src="https://img.shields.io/badge/billing-Stripe-635BFF?style=flat-square&logo=stripe&logoColor=white">
  <img alt="Auth: JWT" src="https://img.shields.io/badge/auth-JWT-000000?style=flat-square&logo=jsonwebtokens&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/runs%20with-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white">
  <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square">
</p>

<p align="center">
  <a href="#funcionalidades">Funcionalidades</a> ·
  <a href="#stack">Stack</a> ·
  <a href="#inicio-rápido">Inicio rápido</a> ·
  <a href="#api">API</a> ·
  <a href="#cómo-funciona">Cómo funciona</a> ·
  <a href="#desarrollo">Desarrollo</a> ·
  <a href="ROADMAP.md">Hoja de ruta</a> ·
  <a href="CHANGELOG.md">Registro de cambios</a>
</p>

## Funcionalidades

### Importación desde ClinicalTrials.gov

- **Todo el registro y, después, solo lo que cambió.** `php artisan clinicaltrials:fetch` lee la API v2 de producción (`https://clinicaltrials.gov/api/v2/studies`, con TLS verificado), sigue `nextPageToken` por todas las páginas y registra cada ejecución. Las ejecuciones posteriores solo piden los estudios actualizados desde la última ejecución correcta.
- **Se ejecuta todos los días.** El planificador encola la importación a las 03:00 (`CLINICAL_TRIALS_SCHEDULE_TIME`); un worker de cola la ejecuta, una importación a la vez.
- **Se puede repetir sin riesgo.** Los estudios se identifican por su número NCT. Cada estudio y sus listas relacionadas se guardan en una sola transacción, y cada lista se sustituye por la actual, incluidas las listas vacías. Un estudio que falla se revierte y se contabiliza; la ejecución continúa.
- **Los fallos son explícitos.** Los límites de tasa (HTTP 429), los errores del servidor y los fallos de conexión se reintentan con espera exponencial, un número limitado de veces. Una ejecución que no puede leer el registro queda marcada como fallida y el comando termina con un error.
- **Los cambios se registran con su valor anterior y posterior.** Un estudio nuevo se registra como nuevo y pasa a ser la referencia. En las importaciones siguientes se comparan el estado general de reclutamiento, los centros (añadidos, eliminados o con un estado de centro distinto), las fechas de inicio y de finalización (con su precisión original: una fecha que solo indica el mes sigue siendo un mes) y los resultados publicados, y se guardan como detalles estructurados. Las listas de centros se comparan por institución y lugar, de modo que un cambio en el orden en que el registro los enumera no cuenta como cambio. Cada cambio tiene una huella estable, por lo que una importación reintentada o repetida no puede registrarlo ni notificarlo dos veces.
- **Las fechas parciales se normalizan** (`2024` → `2024-01-01`, `2024-06` → `2024-06-01`) y el contenido completo del estudio se conserva en `raw_json`.

### Registros duplicados

La importación está preparada para más de un registro (`config/registries.php`; hoy solo se importa ClinicalTrials.gov y los demás están previstos en la hoja de ruta). Cada entrada guarda su registro (`source`) y su identificador en él (`source_id`), único por registro, de modo que volver a importar una entrada la actualiza.

- **Los estudios registrados más de una vez se muestran una sola vez.** Los identificadores de registro de cada entrada (el suyo y los que cita, como un número ISRCTN o EudraCT entre los identificadores secundarios de ClinicalTrials.gov) se normalizan (`ISRCTN 12345678` e `isrctn12345678` son el mismo) y se comparan. Las entradas que comparten uno se enlazan y se agrupan: se muestra la entrada del registro que aparece primero en `config/registries.php` y las demás se conservan como sus otros registros. Los números de protocolo del patrocinador, los números de subvención y los títulos nunca se usan para fusionar estudios.
- **Las coincidencias dudosas esperan a una persona.** Dos entradas del mismo registro que comparten un identificador, o un grupo que contendría dos entradas de un mismo registro, no se fusionan: sus enlaces se marcan como `conflict`. Los operadores listan los enlaces en `GET /api/admin/trial-links` y los confirman o rechazan; las importaciones posteriores respetan la decisión. Un enlace cuyo identificador compartido desaparece se elimina y el grupo se recalcula.
- **Un estudio, una alerta.** Las búsquedas muestran cada estudio una vez, un cambio en cualquiera de sus registros se compara con el estudio, y la misma novedad comunicada por un segundo registro (la aparición del estudio en él o el mismo estado) no vuelve a coincidir ni a enviarse por correo. Un resumen tiene un elemento por estudio.
- **Datos existentes.** Ejecute `php artisan trials:reconcile-duplicates` una vez después de actualizar para indexar los identificadores de los estudios ya guardados; las importaciones lo hacen con cada estudio que guardan.

### Búsqueda

- Búsqueda paginada con filtros por palabras del título y del resumen (texto completo de MySQL), afección, palabra clave, término MeSH, estado de reclutamiento, fase, tipo de estudio, patrocinador, país, ciudad, fechas de inicio, de primera publicación y de última actualización, resultados y distancia a un punto.
- Una página de estudio con todas las listas relacionadas: centros, intervenciones, brazos, criterios de valoración, elegibilidad, identificadores, colaboradores, términos MeSH, publicaciones con enlaces a PubMed y los cambios de estado registrados por la importación.

### Alertas

- Una alerta es una búsqueda guardada. Después de cada importación se registran los estudios nuevos y actualizados que coinciden con ella.
- Los correos se envían en cuanto un estudio coincide, o en un resumen diario o semanal. Los cambios de estado, como que un estudio empiece a reclutar, pueden incluirse o no.
- Las alertas básicas son gratuitas. Todos los planes tienen el mismo límite de búsquedas guardadas (`SAVED_SEARCH_LIMIT`, 100 de forma predeterminada); las búsquedas guardadas creadas por encima de un límite inferior se conservan.

### Resúmenes con IA (Pro AI y Team AI)

- Una búsqueda guardada puede tener un resumen con IA, diario o semanal, en cualquiera de los siete idiomas indicados más abajo. Desactivarlo mantiene la alerta básica en funcionamiento.
- El backend selecciona los cambios que coincidieron con la búsqueda guardada en el periodo del informe, los cuenta y los ordena según los criterios de la búsqueda (por ejemplo, un centro que empieza a reclutar en un país que la búsqueda vigila queda por encima de un cambio de fecha). El modelo solo redacta un breve resumen factual de cada cambio a partir de los hechos registrados.
- Cada resumen muestra la búsqueda guardada, el periodo, el número de actualizaciones coincidentes, los cambios más relevantes con el motivo de la coincidencia y, para cada estudio, su número NCT, la fecha de actualización en el registro y el enlace a ClinicalTrials.gov (que el servidor construye a partir del número NCT validado). Los datos ausentes se indican como ausentes, y el resumen avisa cuando solo enumera las actualizaciones más relevantes.
- Al modelo solo se envían datos públicos del registro: ningún dato de la cuenta, dirección de correo ni nota del usuario. Su respuesta debe ser JSON vinculado a los cambios solicitados; se rechazan los resúmenes con enlaces, con otros números NCT o con afirmaciones sobre elegibilidad, tratamiento o eficacia.
- Cuando la IA está desactivada, falla tras un número limitado de reintentos o devuelve una salida no válida, el resumen usa un texto factual construido a partir de los cambios registrados, y la edición registra el motivo (`ai_disabled`, `provider_error`, `invalid_output`).
- Cada texto de resumen se almacena en caché por versión del cambio, idioma y versión del prompt, y lo reutilizan todos los suscriptores; la relevancia se calcula por búsqueda guardada.
- Cada edición tiene una identidad estable (destinatario o equipo, búsqueda guardada y periodo) y una fila de entrega por destinatario, de modo que las repeticiones del planificador y los reintentos de jobs no envían nada dos veces. El acceso de pago y la pertenencia al equipo se vuelven a comprobar antes del envío.
- Hay dos ediciones de ejemplo con datos sintéticos en `/digests/samples`, accesibles sin cuenta.

### Equipos (Team AI)

- Un suscriptor de Team AI es propietario de un equipo, con una única suscripción. El propietario invita por correo a usuarios registrados, que solo se unen si aceptan.
- Los miembros ven las búsquedas guardadas compartidas y los informes de resúmenes del equipo, y eligen si reciben por correo los resúmenes del equipo. Solo el propietario modifica el equipo, sus miembros y sus búsquedas compartidas. Todas las rutas de equipo comprueban la pertenencia.
- Cuando termina la suscripción Team AI del propietario, las búsquedas compartidas y los informes se pausan; los resúmenes con IA propios de un miembro dependen de su propio plan.

### Cuentas, planes y facturación

- Registro con confirmación por correo, inicio de sesión con JWT, renovación del token, cierre de sesión, restablecimiento de contraseña y cambios de perfil y de contraseña.
- Roles y permisos aplicados en todas las rutas protegidas: `view_clinical_trials`, `create_alerts`, `manage_users`, `manage_plans`, `manage_roles` y `manage_imports`.
- Planes, en dólares estadounidenses:

  | Plan | Precio | Incluye |
  |---|---|---|
  | Free | $0 | Búsqueda, filtros, detalles de los estudios, historial de cambios, búsquedas guardadas y alertas básicas por correo |
  | Pro AI | $19 al mes | Resúmenes con IA de sus propias búsquedas guardadas |
  | Team AI | $99 al mes por equipo | Búsquedas guardadas compartidas, resúmenes con IA compartidos y una página de informe para cada resumen del equipo |

- Las cuentas nuevas empiezan en Free, sin periodo de prueba.
- Planes de pago mediante Stripe con Laravel Cashier: Stripe Checkout para la primera suscripción, cambio entre Pro AI y Team AI con prorrateo, cancelación al final del periodo, reanudación y el portal del cliente de Stripe. Los webhooks de Stripe mantienen sincronizado el plan de la cuenta. Tras una cancelación, las funciones de pago se mantienen hasta el final del periodo pagado.
- Basic, Pro y Enterprise son planes heredados. Ya no se venden y no dan acceso a funciones de IA; las suscripciones existentes a ellos, sus precios y sus clientes de Stripe se dejan como están, y sus suscriptores pueden seguir cancelando, reanudando, usando el portal del cliente o pasar a un plan actual.

### Aplicación web

Una aplicación de una sola página en Vue 3 servida por Laravel: páginas de búsqueda y de estudio, alertas con sus coincidencias y la configuración del resumen con IA, informes de resúmenes con IA, resúmenes de ejemplo, equipos (miembros, invitaciones y búsquedas compartidas), cuenta y plan, y administración de usuarios, planes, roles e importaciones.

### Idiomas

La aplicación web, los mensajes de la API, los correos y los resúmenes con IA están disponibles en inglés (el idioma predeterminado), portugués de Brasil, español, chino simplificado, chino tradicional, japonés y coreano.

- La aplicación web se abre en el idioma del navegador cuando está disponible (si no, en inglés) y tiene un menú de idioma en la cabecera, en las páginas de inicio de sesión y en la página de la cuenta. Las fechas, los números y los precios siguen el idioma elegido.
- Cada cuenta guarda su idioma (`locale`): se define en el registro y se cambia con el menú o con `PUT /api/account/locale`. Los correos (confirmación, restablecimiento de contraseña, alertas, invitaciones a equipos) usan el idioma del destinatario; un resumen con IA usa el idioma elegido para su búsqueda guardada.
- La API responde en el idioma de la cabecera `Accept-Language`, que la aplicación web siempre envía, y recurre al inglés en su defecto. La respuesta incluye `Content-Language`.
- Los datos del registro (títulos de los estudios, resúmenes, afecciones, centros) se mantienen en el idioma de ClinicalTrials.gov; solo se traduce la interfaz que los rodea.

Las traducciones están en `lang/<locale>/` y `lang/<locale>.json` para el backend y en `resources/js/i18n/locales/` para la aplicación web. El inglés es la fuente: las pruebas fallan cuando a otro idioma le falta una clave o un marcador de posición.

> Los datos de los estudios proceden de un registro público. No constituyen consejo médico, ni una recomendación de tratamiento, ni una decisión sobre quién puede participar en un estudio.

## Stack

Versiones instaladas el 2026-11-23. Las restricciones están en `composer.json` y `package.json`.

| Capa | Tecnología |
|---|---|
| Lenguaje y framework | PHP 8.6, Laravel 13.43 |
| Base de datos | MySQL 8.4 LTS (datos, búsqueda de texto completo, cola y sesiones) |
| Caché | Redis 8.10 mediante Predis 3.7 (caché, limitación de tasa, lista negra de JWT) |
| Autenticación | JWT con `tymon/jwt-auth` 2.3 |
| Facturación | Stripe mediante Laravel Cashier 16.11 |
| Cliente HTTP | Guzzle 8.6 (cliente HTTP de Laravel) para la API de ClinicalTrials.gov |
| Correo | Laravel Mail y notificaciones (`MAIL_MAILER`; `log` en desarrollo local) |
| Aplicación web | Vue 3.5, Vue Router 5.5, Pinia 4.1, TypeScript 7.0, Public Sans (Google Fonts) |
| Build del front-end | Vite 8.3, `laravel-vite-plugin` 3.2, `@vitejs/plugin-vue` 6.0 |
| Pruebas y calidad de PHP | PHPUnit 13.4, Mockery 1.6, Faker 1.24, Collision 8.9, Laravel Pint 1.32, Ignition 2.13 (páginas de error) |
| Pruebas y calidad del front-end | Vitest 5.1, Vue Test Utils 2.6, jsdom 31, `vue-tsc` 3.3 (sobre `@typescript/typescript6` 6.0) |
| Herramientas | Composer 2.11, Node.js 26, npm, Laravel Tinker 3.2 |
| Contenedores | Docker Compose: PHP-FPM, worker de cola, planificador, MySQL, Redis, nginx 1.30, Node.js (Vite) |
| CI | GitHub Actions |
| IA | Cualquier API de chat completions compatible con OpenAI, mediante el cliente HTTP de Laravel (opcional) |
| Servicios externos | API v2 de ClinicalTrials.gov, Stripe, un proveedor de IA compatible con OpenAI, PubMed (enlaces por PMID) |

Laravel Breeze 2.4 y Laravel Sail 1.71 están instalados como dependencias de desarrollo procedentes del esqueleto de Laravel, pero no se usan: la aplicación tiene su propio front-end en Vue y se ejecuta con su propio archivo de Docker Compose. TypeScript 7 comprueba los archivos `.ts`. Su compilador nativo ya no tiene la API de JavaScript que necesita `vue-tsc`, así que `npm run typecheck:vue` ejecuta `vue-tsc` sobre el paquete oficial de TypeScript 6, `@typescript/typescript6` (`scripts/vue-tsc.cjs`), para comprobar los archivos `.vue`; `npm run typecheck` ejecuta ambos. Redis usa Predis porque la extensión phpredis todavía no compila en PHP 8.6. `package.json` sustituye `glob` y `minimatch` por versiones corregidas, porque `@vue/test-utils` sigue dependiendo de versiones vulnerables.

## Inicio rápido

### Requisitos

- Docker y Docker Compose
- Los puertos `8000` (web), `3306` (MySQL) y `5173` (Vite) libres en el host, u otros puertos definidos en `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `VITE_PORT`)

### Levantar el stack

```sh
cp .env.example .env
docker-compose up -d --build
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan clinicaltrials:fetch --max-pages=1
```

`.env.example` ya contiene los valores de base de datos que usa `docker-compose.yml`. Al arrancar, el contenedor `app` espera a MySQL, ejecuta `composer install` si falta `vendor/`, genera `APP_KEY` y `JWT_SECRET` cuando están vacíos y ejecuta las migraciones. Los contenedores `queue` y `scheduler` ejecutan los jobs encolados y la programación diaria, `redis` aloja la caché y el contenedor `node` ejecuta el servidor de desarrollo de Vite.

Abra `http://localhost:8000` e inicie sesión con el administrador creado por el seeder, `admin@admin.com` / `password`. Estas credenciales son solo para desarrollo local.

`--max-pages=1` importa una primera página de 100 estudios. Sin esa opción, la primera ejecución lee todo el registro, lo que tarda horas.

Para compilar la aplicación web en lugar de ejecutar el servidor de desarrollo: `docker-compose run --rm node npm run build`.

### Configuración de la facturación

La búsqueda y las alertas básicas funcionan sin Stripe. Para vender Pro AI y Team AI:

1. Defina `STRIPE_KEY`, `STRIPE_SECRET` y `STRIPE_WEBHOOK_SECRET` en `.env`.
2. En Stripe, cree dos precios mensuales recurrentes en USD: $19.00 para Pro AI y $99.00 para Team AI. Ponga sus IDs en `STRIPE_PRICE_PRO_AI` y `STRIPE_PRICE_TEAM_AI`.
3. Ejecute `docker-compose exec app php artisan billing:check-prices`. Copia los IDs a los planes y comprueba que cada precio está activo y cobra cada mes el importe del plan en USD; si no, falla e indica el problema. El checkout realiza la misma comprobación y rechaza un precio que no coincida.
4. Apunte un webhook de Stripe a `https://<your-host>/stripe/webhook` con los eventos `customer.subscription.*` e `invoice.*`.

Suscripciones existentes: la migración `2026_11_24_000001_add_free_and_ai_plans` marca Basic, Pro y Enterprise como heredados sin cambiar sus precios, los IDs de precio de Stripe, las suscripciones ni los clientes. Un suscriptor de un plan heredado lo conserva, facturado como antes, sin funciones de IA. Mantenga definido el `stripe_price_id` de los planes heredados (página Planes en Administración) para que los webhooks sigan asociándose a sus suscripciones; no hace falta asignar nada a los planes nuevos. Las cuentas que seguían dentro del antiguo periodo de prueba de 14 días al registrarse ahora usan Free.

### Configuración de la IA

Los resúmenes con IA funcionan sin proveedor: en ese caso usan el resumen factual. Para que los textos los redacte un modelo, defina en `.env`:

```sh
AI_DIGESTS_ENABLED=true
AI_BASE_URL=https://api.openai.com/v1   # any OpenAI-compatible API
AI_API_KEY=...                          # server-side only
AI_MODEL=...                            # a model name the provider accepts
```

Límites: `AI_TIMEOUT` (segundos, 30), `AI_RETRIES` (2) y `AI_RETRY_SLEEP_MS` (2000), `AI_MAX_INPUT_CHARS` (12000 caracteres por solicitud), `AI_MAX_OUTPUT_TOKENS` (1200) y `AI_DIGEST_MAX_ITEMS` (10 estudios por resumen). El log registra el estado, la duración, los aciertos de caché y el número de tokens de cada resumen, nunca la clave de la API ni la entrada y salida del modelo. Los operadores ven la generación de cada edición (`ai`, `partial` o `fallback`) y el motivo de la alternativa en `GET /api/admin/digests`.

El planificador encola los resúmenes diarios a las 07:30 y los semanales los lunes a las 07:30 (`php artisan digests:dispatch daily|weekly`); el contenedor `queue` los genera y los envía.

### Probarlo en local

1. Inicie sesión como el administrador del seeder e importe una página de estudios (ver arriba).
2. Cree una alerta en la página Alertas: funciona en Free. Su sección de resumen con IA muestra los resúmenes de ejemplo y la acción para mejorar el plan.
3. Abra `http://localhost:8000/digests/samples` para ver las dos ediciones de ejemplo.
4. Sin Stripe, asigne manualmente un plan a una cuenta en Tinker para probar las pantallas de pago, por ejemplo con una fila local en `subscriptions` con `stripe_status` `active` y el `plan_id` de Pro AI o de Team AI. Después active un resumen con IA, ejecute otra importación y `php artisan digests:dispatch daily --end=tomorrow` (así el periodo incluye el día de hoy); con `MAIL_MAILER=log`, el correo del resumen se escribe en `storage/logs/laravel.log` y el informe aparece en la página Resúmenes con IA.

## API

Todas las rutas están bajo `/api` y devuelven JSON. Las rutas protegidas requieren `Authorization: Bearer <access_token>`.

| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| `POST` | `/auth/register` | pública | Crea una cuenta (`locale` opcional; si no, el idioma de la solicitud); devuelve un token (201) |
| `POST` | `/auth/login` | pública, 5 intentos por minuto | Token, o HTTP 401 si las credenciales no son válidas |
| `POST` | `/auth/refresh` | token | Token nuevo |
| `POST` | `/auth/logout` | token | Invalida el token (204) |
| `GET` | `/auth/me` | token | Usuario actual, roles, permisos y resumen de facturación |
| `POST` | `/auth/forgot-password` | pública | Envía por correo un enlace de restablecimiento |
| `POST` | `/auth/reset-password` | pública | Define una contraseña nueva con el token recibido por correo |
| `GET` | `/auth/verify-email/{user}/{hash}` | enlace firmado | Confirma el correo y redirige a la aplicación web |
| `POST` | `/auth/email/verification-notification` | token | Vuelve a enviar el enlace de confirmación |
| `PUT` | `/account/profile`, `/account/password` | token | Cambia el nombre y el correo, o la contraseña |
| `PUT` | `/account/locale` | token | Cambia el idioma de la cuenta (`locale`: `en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/trials` | `view_clinical_trials` | Búsqueda, un resultado por estudio; filtros más abajo (incluido `source[]`, la clave de un registro), `sort`, `page`, `per_page` (hasta 100) |
| `GET` | `/trials/{nct_id}` | `view_clinical_trials` | Estudio con todas sus listas relacionadas, sus `identifiers` normalizados y sus otros registros (`also_registered_as`, o `duplicate_of` para una entrada mostrada bajo otra) |
| `GET` `POST` | `/alerts` | `create_alerts`, correo confirmado | Lista o crea búsquedas guardadas; `team_id` para las compartidas de un equipo (el propietario crea, los miembros listan) |
| `GET` `PATCH` `DELETE` | `/alerts/{id}` | propietario; los miembros del equipo pueden leer las compartidas | Lee, modifica o elimina una búsqueda guardada, incluidos `ai_digest_enabled`, `ai_digest_frequency` (`daily`, `weekly`) y `ai_digest_language` (`en`, `pt_BR`, `es`, `zh_CN`, `zh_TW`, `ja`, `ko`) |
| `GET` | `/alerts/{id}/matches` | propietario, miembros del equipo | Estudios que coincidieron |
| `GET` | `/plans` | pública | Free, Pro AI y Team AI con precios en USD y funciones |
| `GET` | `/digests/samples` | pública | Dos ediciones de resumen de ejemplo con datos sintéticos |
| `GET` | `/digests`, `/digests/{id}` | correo confirmado; destinatario o miembro del equipo | Ediciones de resúmenes (`?team_id=` para un equipo) y un informe con su contenido |
| `GET` `POST` | `/teams` | correo confirmado | Sus equipos; crear uno (Team AI) |
| `GET` `PATCH` `DELETE` | `/teams/{id}` | miembro (lectura), propietario (modificar, eliminar) | Un equipo con sus miembros |
| `PATCH` | `/teams/{id}/membership` | miembro | Recibir o no por correo los resúmenes del equipo |
| `POST` `DELETE` | `/teams/{id}/invitations`, `/teams/{id}/invitations/{invitation}` | propietario | Invita por correo a un usuario registrado; retira una invitación |
| `DELETE` | `/teams/{id}/members/{user}` | propietario, o el propio miembro para salir | Elimina a un miembro |
| `GET` `POST` | `/team-invitations`, `/team-invitations/{id}/accept`, `/team-invitations/{id}/decline` | el usuario invitado | Invitaciones dirigidas a su correo; aceptar o rechazar |
| `GET` | `/billing` | correo confirmado | Plan efectivo y suscripción |
| `POST` | `/billing/plan` | correo confirmado | Elige un plan; devuelve `checkout_url` cuando hace falta un pago |
| `POST` | `/billing/cancel`, `/billing/resume` | correo confirmado | Cancela al final del periodo, o mantiene la suscripción |
| `GET` | `/billing/portal` | correo confirmado | URL del portal del cliente de Stripe |
| `GET` `PATCH` | `/admin/users`, `/admin/users/{id}` | `manage_users` | Lista usuarios; cambia roles y plan |
| `GET` `POST` `PATCH` `DELETE` | `/admin/plans`, `/admin/plans/{id}` | `manage_plans` | Todos los planes, incluidos los heredados; gestión de planes |
| `GET` `PUT` | `/admin/roles`, `/admin/permissions`, `/admin/roles/{id}/permissions` | `manage_roles` | Gestión de los permisos de los roles |
| `GET` `POST` | `/admin/imports` | `manage_imports` | Ejecuciones de importación; encola una importación (202) |
| `GET` | `/admin/digests` | `manage_imports` | Ediciones de resúmenes con estado, generación, motivo de la alternativa y métricas (sin contenido) |
| `GET` | `/admin/trial-links` | `manage_imports` | Enlaces entre entradas que comparten un identificador de registro; `status` = `conflict` (predeterminado), `auto`, `confirmed` o `rejected` |
| `PATCH` | `/admin/trial-links/{id}` | `manage_imports` | Confirma o rechaza un enlace (`status`: `confirmed` o `rejected`); las entradas se reagrupan |

Filtros de búsqueda: `q`, `condition`, `keyword`, `mesh_term`, `sponsor`, `country`, `city`, `status[]`, `phase[]`, `study_type[]`, `has_results`, `start_date_from`/`_to`, `first_posted_from`/`_to`, `last_update_from`/`_to`, y `latitude` + `longitude` + `radius_km`. `sort` acepta `-last_update_post_date` (predeterminado), `last_update_post_date`, `±start_date`, `±study_first_post_date`, `±brief_title` y `relevance`.

Un token ausente o no válido devuelve 401; la falta de permisos o los registros de otro usuario o equipo, 403; una función de pago sin el plan correspondiente (resúmenes con IA, espacio de equipo), 402; el límite de búsquedas guardadas, 403; y una entrada no válida, 422 con los errores por campo.

```sh
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"password"}' | jq -r .access_token)

curl "http://localhost:8000/api/trials?condition=asthma&status[]=RECRUITING&country=Brazil" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## Cómo funciona

```text
Import:  scheduler → ImportClinicalTrialsJob → ClinicalTrialImporter
           → ClinicalTrialsGovClient (pages, retries) → StudyMapper → ClinicalTrialSynchronizer (one transaction per study)
           → ClinicalTrialsImported event → MatchAlertsJob → AlertMatcher → AlertNotifier (email)
Digests: scheduler → digests:dispatch → GenerateDigestJob → DigestGenerator (identity, lock, entitlement)
           → DigestBuilder (DigestRanker, ChangeSummarizer → AiProvider or factual text) → DigestDeliverer (email)
API:     nginx → PHP-FPM (Laravel) → routes/api.php → Form Request → controller → service
Web app: Laravel serves resources/views/app.blade.php → Vue 3 + Vue Router + Pinia → /api
```

- `app/Services/ClinicalTrials/Import/` contiene la importación. Cada registro es un `RegistrySource` (`app/Services/Registries/`) con un `StudyRegistryClient` y un `StudyPayloadMapper`; `clinicaltrials:fetch --source=<key>` elige uno (`clinicaltrials_gov` de forma predeterminada) y cada registro mantiene su propio cursor incremental. El mapper es una función pura del contenido recibido y el sincronizador se encarga de la persistencia.
- `app/Services/Registries/` contiene la lista de registros (`SourceRegistry`), la normalización de identificadores (`IdentifierParser`) y la deduplicación (`DuplicateLinker`, que se ejecuta en la transacción de cada estudio).
- `app/Services/ClinicalTrials/Search/` construye la búsqueda a partir de pequeñas clases `TrialFilter` registradas en `AppServiceProvider`. Las alertas reutilizan los mismos criterios y filtros.
- `app/Services/ClinicalTrials/Changes/` contiene los hechos monitorizados de un estudio y el `ChangeDetector` puro que los compara.
- `app/Services/Alerts/` aplica el límite de búsquedas guardadas, cruza las importaciones con las alertas y envía los correos. `app/Services/Billing/` contiene las reglas de los planes y `Entitlements`; a Stripe se accede mediante la interfaz `SubscriptionGateway`. `app/Services/Teams/` gestiona los equipos y las invitaciones.
- `app/Services/Digests/` construye los resúmenes; al modelo se accede mediante la interfaz `AiProvider` (`OpenAiCompatibleProvider`).
- `app/Http/` mantiene los controladores ligeros: los Form Requests validan, los servicios deciden y los API Resources dan forma al JSON.
- El stack de Docker tiene siete servicios: `app` (PHP 8.6 FPM), `queue`, `scheduler`, `mysql` (MySQL 8.4), `redis` (Redis 8.10, el almacén de caché, que también usan el limitador de tasa y la lista negra de JWT), `nginx` y `node` (Node.js 26 y Vite). Laravel se comunica con Redis mediante Predis (`REDIS_CLIENT=predis`), porque la extensión phpredis todavía no compila en PHP 8.6.

## Desarrollo

```sh
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/pint --test
docker-compose run --rm node npm run typecheck
docker-compose run --rm node npm test
```

- Las pruebas de PHP se ejecutan en una base de datos MySQL dedicada, `clinical_saas_test`, que crea `docker/mysql/init/` cuando se crea por primera vez el volumen de MySQL. Para un volumen existente, créela una vez: `docker-compose exec mysql mysql -uroot -proot -e "CREATE DATABASE clinical_saas_test; GRANT ALL ON clinical_saas_test.* TO 'clinical_user'@'%';"`.
- Las pruebas de importación usan `Http::fake()` con una respuesta de la API grabada en `tests/Fixtures/`; las pruebas de facturación usan `Tests\Fakes\FakeSubscriptionGateway` y las de resúmenes `Tests\Fakes\FakeAiProvider`, con el correo simulado. Las pruebas nunca llaman a ClinicalTrials.gov, a Stripe ni a un proveedor de IA.
- GitHub Actions: `.github/workflows/packages.yml` audita los archivos lock de Composer y npm (la insignia *packages*), y `.github/workflows/ci.yml` ejecuta Pint, las pruebas de PHP, la comprobación de tipos, las pruebas de Vue y el build en los pushes y pull requests a `main`, en los tags y manualmente desde la pestaña Actions. Las ramas fluyen de `dev` a `hom` y a `main`.

El esquema se define únicamente mediante las migraciones de `database/migrations/`. Añada una migración nueva para cada cambio de esquema; no edite migraciones que ya se hayan ejecutado.

## Estructura del proyecto

```text
app/
  Console/Commands/      clinicaltrials:fetch, trials:reconcile-duplicates, alerts:send-digests, digests:dispatch, billing:check-prices
  Enums/                 statuses, phases, permissions, alert and digest frequency, team role
  Http/                  Controllers/Api (and Admin/), Requests/, Resources/, Middleware/
  Jobs/, Events/, Listeners/, Notifications/, Policies/
  Models/                User, Role, Permission, Plan, Subscription, Alert, AlertMatch, ImportRun,
                         Team, TeamMember, TeamInvitation, Digest, DigestItem, DigestDelivery, ChangeSummary
  Models/ClinicalTrials/ ClinicalTrial, its related lists and ClinicalTrialChange
  Services/              Auth/, ClinicalTrials/{Import,Search,Changes}, Alerts/, Billing/, Teams/, Digests/, Ai/, Admin/
config/                  clinicaltrials.php, billing.php, ai.php, auth.php, jwt.php
lang/                    API, email and digest text per language (en, pt_BR, es, zh_CN, zh_TW, ja, ko)
database/                migrations/, factories/, seeders/
docs/                    logo, registries evaluation
resources/js/            Vue app: pages/, components/, stores/, api/, i18n/ (catalogs per language)
routes/                  api.php, web.php (web app), console.php (schedule)
tests/                   Feature/, Unit/, Fixtures/
```

## Fuente de los datos

Los datos de los estudios proceden de [ClinicalTrials.gov](https://clinicaltrials.gov/), gestionado por la Biblioteca Nacional de Medicina de EE. UU. Los registros describen estudios inscritos y no constituyen consejo médico. Consulte el registro oficial antes de basarse en cualquier detalle de un estudio. [docs/registries-evaluation.md](docs/registries-evaluation.md) evalúa otros registros (WHO ICTRP, EU CTR/CTIS y ReBEC).

## Licencia

[MIT](LICENSE-MIT).
