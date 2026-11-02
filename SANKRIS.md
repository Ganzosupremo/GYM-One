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

Puntos a revisar:
- **Inscripción anual US$75** se cargó como **Q585** (tipo de cambio ≈7.8, igual que en las cuotas mensuales). Ajústenlo si cobran otro monto en quetzales.
- **Clase de evaluación inicial** se cargó a **Q0**; el documento no indica precio.
- **Advanced Competitivo** no tiene horarios cargados ("horarios específicos por nivel").
- El horario de apertura se calculó de la primera a la última clase de cada día.
- Los días se guardan en español ("Lunes", "Sábado"...), por eso `LANG_CODE` debe ser `ES`.

## Instalación

- **Instalación nueva**: usen el instalador de Sankris (`Ganzosupremo/GYM-One-Installer`); ya crea las tablas nuevas y carga los datos 2026.
- **Instalación existente**: ejecuten una sola vez `assets/SQL/sankris_migration.sql` y luego `assets/SQL/sankris_seed.sql` (por ejemplo en phpMyAdmin o con `mysql gymone < archivo.sql`).

## Pagos

Lo que GYM One trae hoy:
- **Venta en recepción**: membresías y productos cobrados en **efectivo** o **tarjeta (POS)**, con factura PDF y estadísticas de ingresos.
- **Saldo de perfil** (prepago) que se puede recargar y usar para pagar.
- **PayPal / Stripe**: existe una pantalla de "pasarelas" pero está desactivada y sin terminar (solo un botón de prueba de PayPal en USD). No hay cobro en línea funcionando.

Para Guatemala, la opción recomendada para cobros en línea es **Recurrente** (fintech guatemalteca: tarjetas en quetzales, links de pago y suscripciones mensuales recurrentes, con API). Alternativas: los botones/pasarelas de los bancos (BAC Credomatic, Banco Industrial/Neonet vía Cybersource) o PayPal (cobra en USD). Stripe no abre cuentas para empresas en Guatemala.
