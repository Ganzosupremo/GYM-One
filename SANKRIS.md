# Sankris Gymnastics: adaptación de GYM One

Este repositorio es una versión de [GYM One](https://github.com/mayerbalintdev/GYM-One), desarrollado por los colaboradores de GYM One, adaptada para **Sankris Gymnastics Art Studio** (gimnasia artística, Guatemala). La licencia original (`LICENSE`) sigue aplicando: se mantiene la atribución a GYM One en el pie de página y en las facturas.

## Qué cambió respecto al original

**Marca**
- Logo, favicon, banner e icono de Sankris (`assets/img/brand/`). `logo-white.png` es la versión blanca para el menú lateral del panel.
- Paleta de colores tomada del logo: rosa `#eb008b`, morado `#90278e`, violeta `#6a50a0`, azul `#3d59a7`. Reemplaza el azul de GYM One en todo el panel y el sitio público.
- `example.env` con valores para Sankris: `LANG_CODE=ES`, `CURRENCY=Q`, `COUNTRY=Guatemala`.

**Gimnasia artística**
- Nueva tabla `programs`: cada programa (Baby, Start, Intermediate, Advanced Recreativo, Advanced Competitivo, Tae Kwon Do, Golden) con rango de edad, cupo por grupo, duración y área.
- Horarios (`timetable`) con programa, cupo y marca de **clase de evaluación inicial**.
- Membresías (`tickets`) vinculadas a su programa.
- Ficha de gimnasta en el panel (Alumnos → editar): edad calculada, programas sugeridos por edad, programa asignado, nivel (p. ej. Nivel 1, 2, 3+), nombre y teléfono del padre/madre/tutor y notas médicas.
- Nueva página pública **Clases y horarios** (`/classes/`) que muestra cada programa con sus horarios, evaluaciones y cuotas.
- Calendario del panel en español, de 07:00 a 20:00; ahora se elimina un solo horario (antes se borraban todas las clases con el mismo nombre).
- Terminología en español adaptada: "pases/boletos" → "membresías y cuotas", "entrenadores" → "coaches", "miembros" → "alumnos", "entrenamiento" → "clase".
- El actualizador del panel descarga las actualizaciones de `Ganzosupremo/GYM-One` (este repositorio), para que una actualización no borre la adaptación.

## Datos 2026 incluidos

`assets/SQL/sankris_seed.sql` carga los 7 programas, 57 horarios semanales, 16 cuotas en quetzales y el horario de apertura, según "Servicios, Horarios y Precios 2026".

Confirmado por Sankris:
- **Inscripción anual**: Q585.
- **Clase de evaluación inicial**: gratis (Q0).
- **Advanced Competitivo**: los horarios por nivel están pendientes; se cargan en Horarios cuando los tengamos.

Notas:
- El horario de apertura se calculó de la primera a la última clase de cada día.
- Los días se guardan en español ("Lunes", "Sábado"...), por eso `LANG_CODE` debe ser `ES`.

## Módulo Sankris: familias, planes, reservas y cobros (roadmap fases 1–4)

En el panel, el enlace **Sankris: familias y cobros** abre `admin/sankris/`. Reemplaza lo que hoy se hace en CrossHero, siguiendo el "Roadmap de reemplazo de CrossHero con Gym One". Convive con GYM One: usa su login del personal, sus programas y sus horarios, y guarda todo lo nuevo en tablas `sk_`.

| Fase | Qué hay |
|---|---|
| 1. Familias y alumnos | Familia con varios responsables y varios hijos; un solo responsable pagador. Ficha del alumno (nacimiento, edad, programa, nivel, contacto de emergencia, etiquetas, estado activo/prueba/inactivo, autorización de fotos). Notas médicas visibles sólo para administración y coaches. Roles del personal (Administración, Recepción, Coach) y su tabla de permisos. Historial de cambios con quién y cuándo. |
| 2. Planes y suscripciones | Catálogo de planes (mensualidad, pase de clases, inscripción, campamento, reposición) con precio, período de cobro, créditos y su propio período de renovación, y programas permitidos. La suscripción copia precio y condiciones al contratar: cambiar la tarifa del plan no toca contratos existentes. Precio personalizado, descuento fijo o porcentual por N cobros, pausas (el cobro se corre a la fecha de reanudación), cancelación y reactivación. |
| 3. Reservas y asistencia | Reservas manuales y fijas (se generan solas cada día). Valida día, cupo, ventana de reserva, créditos y programa. Cancelación a tiempo devuelve el crédito; tarde, no (salvo que administración lo fuerce). Reposiciones con límite mensual. Clases de evaluación sin suscripción. Cancelación de una clase o de todo un día (asueto) con devolución de créditos. Asistencia por alumno desde recepción o el coach. Alertas cuando una reserva fija no se pudo crear. |
| 4. Facturación y cobros | Facturas (SK-AAAA-000001) separadas de los pagos. Facturas recurrentes de las suscripciones. Pagos en efectivo, transferencia, tarjeta (POS) y Stripe; pagos parciales, saldo a favor y saldo por familia. Vencimientos y antigüedad de la deuda. Stripe: guardar tarjeta, enlace de pago, cobro automático con reintentos (1, 3 y 7 días, configurable), reembolsos y conciliación. Anulación de facturas con motivo. |

Reglas configurables (sin tocar código): en **Reglas y Stripe** (moneda, días para pagar, anticipación de la factura, calendario de reintentos, anticipación de reservas fijas) y por programa en **Clases y asistencia** (días para reservar, horas para cancelar sin perder crédito, reposiciones por mes). Los cupos, coaches y salas se editan en **Horarios de clases**.

Garantías que pide el roadmap:
- Un reintento nunca duplica un cargo: cada intento de cobro tiene una clave de idempotencia en Stripe, los avisos de Stripe se procesan una sola vez y los formularios de pago no se registran dos veces aunque se envíen dos veces.
- El historial financiero no cambia con la tarifa: facturas y suscripciones guardan su propio precio.
- Reserva, asistencia y acceso al gimnasio son registros distintos (el acceso sigue siendo el de GYM One).

Pruebas automáticas: `php sankris/tests/run.php` crea una base de prueba temporal, corre las pruebas y la borra.

## Instalación

- **Instalación nueva**: usen el instalador de Sankris (`Ganzosupremo/GYM-One-Installer`); ya crea las tablas nuevas y carga los datos 2026. Las tablas del módulo se crean solas la primera vez que se abre **Sankris: familias y cobros**.
- **Instalación existente**: ejecuten `php sankris/bin/migrate.php` (o abran el módulo en el panel), luego `assets/SQL/sankris_seed.sql` una sola vez (phpMyAdmin o `mysql gymone < assets/SQL/sankris_seed.sql`), y en **Planes** pulsen "cargar la lista de precios 2026".
- **Tarea diaria** (cron, una vez al día temprano):
  `15 5 * * * php /ruta/a/gymone/sankris/bin/daily.php >> /var/log/sankris-daily.log 2>&1`
  Reanuda pausas vencidas, emite las facturas de las suscripciones, cobra las tarjetas y corre reintentos, y crea las reservas fijas. Se puede correr varias veces sin duplicar nada.
- La carpeta `sankris/` no debe ser accesible por web (trae su `.htaccess`; en Nginx, bloquéenla).

## Pagos con Stripe

Sankris ya cobra con Stripe, así que el módulo se conecta a esa cuenta:

1. En `.env` agreguen `STRIPE_SECRET_KEY` (mejor una clave restringida) y `STRIPE_WEBHOOK_SECRET`. Nunca se guardan en la base de datos.
2. En Stripe → Desarrolladores → Webhooks agreguen `https://<su dominio>/stripe/webhook.php` con los eventos `checkout.session.completed`, `checkout.session.expired`, `payment_intent.succeeded`, `payment_intent.payment_failed` y `charge.refunded`.
3. En **Reglas y Stripe** confirmen la moneda (por defecto `GTQ`; debe ser una que su cuenta de Stripe acepte) y la dirección pública del sistema.
4. Prueben primero con claves de prueba (`sk_test_...`): la página muestra si está en modo de prueba o real.

Cómo se usa: desde la familia, "Enlace para guardar tarjeta" manda al responsable a Stripe para guardar su tarjeta; con "Cobro automático" activado, la tarea diaria cobra cada factura en su fecha y reintenta si falla. Desde una factura también se puede crear un enlace de pago único o cobrar a la tarjeta guardada. Al volver de Stripe, el responsable ve la página `/sankris-pago/`.

Lo que GYM One trae aparte: venta en recepción (efectivo o POS) con factura PDF, y saldo de perfil prepago. Su pantalla de "pasarelas" PayPal/Stripe sigue sin terminar y no se usa: los cobros en línea van por el módulo Sankris.

## Pendiente (siguientes fases del roadmap)

- Fase 5, portal de familias: hoy los enlaces de Stripe se envían a mano.
- Fase 6, comunicaciones (los 12 flujos de CrossHero) y fase 7, reportes e integración con Sankris Intelligence.
- Integraciones con la App de Reservas, Sankris Intelligence y ManyChat: quedan para después, cuando tengamos sus repositorios.
- Fase 8, migración desde CrossHero: los registros ya tienen `external_id` para guardar el identificador de CrossHero. No retirar CrossHero hasta terminar esta fase.
