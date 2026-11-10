# Preflight y verificación de la migración de plataformas

Este documento describe dos comprobaciones distintas. La comprobación previa
evalúa el esquema antes de migrar; la verificación posterior comprueba el
esquema y los datos después de aplicar la migración. La existencia de este
procedimiento no significa que la migración se haya ejecutado.

## 1. Comprobaciones previas a la migración

`backend/scripts/preflightPlatformMigration.php` es un preflight de solo
lectura. Comprueba que el esquema `mirai_games_test` y sus datos coincidan con
el estado de origen esperado antes de aplicar la migración. No ejecuta la
migración y no puede verificar automáticamente la revisión administrativa de
claves foráneas entrantes desde otros esquemas.

## Comprobaciones locales

El script realiza estas comprobaciones sobre la instancia y el esquema a los
que se conecta:

- Se ejecuta desde PHP CLI y exige `APP_ENV=test` y
  `MYSQL_DATABASE=mirai_games_test`.
- Confirma que el esquema activo sea `mirai_games_test` y que la versión de
  MySQL sea compatible.
- Verifica tablas, motores, cotejamientos y definiciones de columnas esperados.
- Compara índices, claves foráneas definidas en el esquema objetivo y
  restricciones `CHECK` con las definiciones esperadas.
- Comprueba que la cuenta local tenga los permisos de solo lectura admitidos,
  sin roles activos o aplicables ni privilegios no reconocidos.
- Comprueba que no existan ya en el esquema los objetos de la nueva migración.
- Verifica que cada videojuego tenga una relación con al menos una plataforma.
- Comprueba que no haya pedidos `pending` con inventario `reserved`.
- Informa los recuentos de filas de las tablas verificadas y calcula un
  recuento y SHA-256 de los detalles históricos de pedidos como referencia.

Este preflight espera que los objetos de la nueva migración todavía no
existan. No sirve para verificar el esquema resultante después de migrar.

La comprobación local de claves foráneas solo compara las relaciones definidas
en `mirai_games_test`. No certifica que otros esquemas carezcan de relaciones
que apunten a tablas de este esquema.

## 2. Comprobaciones posteriores a la migración

`backend/scripts/verifyPlatformMigration.php` es un verificador independiente
de solo lectura. Se conecta usando la configuración existente, exige
`APP_ENV=test` y `MYSQL_DATABASE=mirai_games_test`, y no crea objetos ni
modifica datos.

Comprueba las columnas `games.rawg_id`, `game_platforms.price`, `stock` e
`is_available`, y los IDs/plataformas de `cart_details` y `order_details`.
También revisa las claves primarias, los índices únicos, las claves foráneas
hacia `game_platforms`, las restricciones CHECK y que los valores de las
variantes sean válidos. Comprueba además que `games.rawg_id` sea nullable y
tenga su índice UNIQUE, y que los tipos de `game_platforms(game_id,
platform_id)` coincidan con los de `cart_details` y `order_details`.

El esquema base define `game_platforms` con PRIMARY KEY `(game_id,
platform_id)`, donde `game_id` es `BIGINT UNSIGNED` y `platform_id` es
`INT UNSIGNED`. Es una clave única compatible con las dos FKs compuestas que
añade la migración; sus columnas referenciantes deben tener exactamente esos
tipos. La comprobación estática del esquema base confirma esa correspondencia.

Los índices UNIQUE de carrito y pedidos incluyen `platform_id`, que es
nullable por compatibilidad. MySQL permite varias filas con `NULL` en una
clave UNIQUE compuesta; por tanto, esos índices no impiden duplicados de
líneas sin plataforma. El backend debe controlar explícitamente esos casos y
evitar crear o fusionar líneas ambiguas. Para líneas con plataforma asignada,
el índice sí protege la unicidad de la combinación.

### Comprobación inmediata de NULL históricos

El verificador comprueba que las líneas actuales de carrito tienen
`platform_id = NULL`. Ejecuta esta comprobación inmediatamente después de
aplicar la migración, con los procesos de la aplicación detenidos y antes de
reanudar escrituras.

La limitación es importante: las tablas no contienen una marca que permita
distinguir filas históricas de filas nuevas. Si el verificador se ejecuta más
tarde, ya no puede identificar por sí solo qué filas de carrito existían al
migrar; la comprobación deja de ser concluyente. El verificador siempre
informa esta limitación.

Para `order_details`, el verificador no exige que la tabla esté vacía ni que
todas sus filas tengan `platform_id = NULL`. No existe una línea base
pre-migración por fila que permita separar pedidos históricos de pedidos
nuevos, así que informa `NOT CONCLUSIVE` y no afirma que haya demostrado la
conservación de los NULL históricos. Esa conservación debe revisarse contra
una referencia previa fiable si se requiere certificarla. No se añade ninguna
columna ni marca auxiliar al esquema.

El verificador solo acredita las claves foráneas locales consultables por la
cuenta de test. No sustituye la revisión administrativa de referencias
entrantes desde otros esquemas.

**No ejecutes la migración ni reanudes la aplicación como parte de esta
verificación sin autorización expresa y sin completar el procedimiento de
despliegue aprobado.**

## Significado de los resultados

- `LOCAL_CHECKS_PASSED`: pasaron las comprobaciones locales del script. No es
  una aprobación global ni autoriza la migración.
- `ADMIN_FK_REVIEW_REQUIRED`: hace falta completar y aprobar de manera
  independiente la revisión administrativa de claves foráneas entrantes.
- `STOP`: el procedimiento no autoriza continuar. Actualmente el script termina
  con `STOP` y código de salida distinto de cero incluso si todas las
  comprobaciones locales pasan, porque no puede validar automáticamente la
  revisión administrativa.

La revisión manual descrita aquí no elimina ni modifica ese `STOP`. No se debe
ejecutar la migración mientras el procedimiento completo no esté aprobado por
los responsables del proyecto y exista un mecanismo fiable para satisfacer
todos sus controles.

## Revisión administrativa de claves foráneas entrantes

La revisión debe realizarse en MySQL Workbench, conectado explícitamente a la
misma instancia que se pretende migrar. La cuenta debe tener visibilidad
suficiente de los esquemas pertinentes en los metadatos de MySQL; de lo
contrario, un resultado vacío podría deberse a falta de visibilidad y no a la
ausencia de relaciones.

Confirma que el esquema objetivo es `mirai_games_test` y revisa las claves
foráneas entrantes a `cart_details` y `order_details` en todos los esquemas
visibles de la instancia. Esta revisión es necesaria porque la migración
sustituye sus claves primarias compuestas; una FK de otra tabla que las
referencie podría impedir el cambio o requerir una adaptación coordinada.

La inspección estática del esquema base y de los archivos SQL de migración
disponibles no encuentra claves foráneas entrantes a esas tablas. Esto no
demuestra que no existan en la instancia MySQL real, pues podrían haberse
creado fuera de estos archivos. No se debe presuponer su ausencia: la consulta
administrativa de abajo sigue siendo obligatoria antes de autorizar.

### Consulta de referencia (propuesta/documentada)

La siguiente consulta es una referencia documentada para realizar esa
comprobación. **No es una transcripción verificada de la consulta histórica
ejecutada en Workbench.**

```sql
SELECT
    CONSTRAINT_SCHEMA AS esquema_origen,
    TABLE_NAME AS tabla_origen,
    COLUMN_NAME AS columna_origen,
    REFERENCED_TABLE_SCHEMA AS esquema_destino,
    REFERENCED_TABLE_NAME AS tabla_destino,
    REFERENCED_COLUMN_NAME AS columna_destino,
    CONSTRAINT_NAME AS nombre_fk
FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = 'mirai_games_test'
  AND REFERENCED_TABLE_NAME IN ('cart_details', 'order_details')
ORDER BY
    CONSTRAINT_SCHEMA,
    TABLE_NAME,
    CONSTRAINT_NAME;
```

Interpreta las filas devueltas como claves foráneas cuyo destino es una de las
dos tablas indicadas. Comprueba especialmente las columnas referenciadas para
identificar dependencias de las claves primarias actuales. Un resultado de
cero filas solo es concluyente si la sesión estaba conectada a la instancia
correcta y la cuenta podía ver suficientemente los metadatos de todos los
esquemas relevantes.

### Resultado reportado para esta revisión

Se informó que la consulta administrativa se ejecutó en MySQL Workbench como
`root` y devolvió cero filas. Este documento registra ese resultado según lo
observado y comunicado para esta revisión; no se ha verificado
independientemente. No basta para autorizar la migración sin confirmar que la
consulta cubrió la instancia y todos los esquemas pertinentes con visibilidad
de metadatos suficiente. Repite y conserva la evidencia para la instancia
objetivo antes de autorizar.

No se conoce el texto literal histórico de la consulta ejecutada. Por tanto, no
se conserva ni se presenta aquí una transcripción histórica. El SQL incluido
arriba es solo una consulta de referencia propuesta/documentada.

Para futuras revisiones, registra junto al resultado la fecha y hora, la
instancia y esquema objetivo, la cuenta administrativa utilizada, la
confirmación de visibilidad suficiente y la evidencia del resultado.

## Cuándo repetir la revisión

Repite la revisión administrativa si cambia la instancia MySQL o el esquema
objetivo. También debe repetirse si cambia la configuración que pueda afectar
la visibilidad de metadatos o las claves foráneas existentes.

## Estado de autorización

El resultado manual de cero filas no cambia el comportamiento del preflight:
el script sigue informando `ADMIN_FK_REVIEW_REQUIRED` y termina en `STOP`.
La revisión manual no es una atestación que el script pueda validar y no
autoriza la migración. **La migración no está autorizada todavía.**
