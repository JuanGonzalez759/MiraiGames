# MiraiGames

Proyecto de tienda online de videojuegos para el proyecto final de DAW.

## Tecnologías

- Frontend: React y Vite (JavaScript).
- Backend: PHP 8.x, PDO y API REST.
- Base de datos: MySQL.

## Estructura

- `frontend/`: aplicación React.
- `backend/`: API REST PHP y configuración de conexión a MySQL.
- `database/`: script de creación del esquema MySQL.
- `docs/`: documentación adicional.
- `tests/`: pruebas del proyecto.

## Requisitos

- Node.js LTS compatible con Vite.
- PHP 8.x con las extensiones `PDO`, `pdo_mysql`, `curl`, `mbstring` y `openssl`.
- MySQL 8.x y la base de datos `mirai_games` creada con `database/mirai_games.sql`.

Comprueba la instalación de PHP con `php --version` y que `php -m` muestre
`PDO` y `pdo_mysql`.

## Desarrollo del frontend

Desde `frontend/`:

```bash
npm install
npm run dev
```

Para generar la compilación:

```bash
npm run build
```

Vite está configurado para reenviar las peticiones `/api` a `http://127.0.0.1:8000`.
El backend incluye `GET /api/health`, el catálogo y sus filtros, autenticación
con sesión y los endpoints autenticados del carrito:
`GET /api/cart`, `POST /api/cart/items`, `PATCH /api/cart/items/{game_id}` y
`DELETE /api/cart/items/{game_id}`. También incluye checkout, webhook e
historial de pedidos; estos endpoints requieren aplicar primero la migración
descrita más adelante.

## Desarrollo del backend

Para desarrollo local, crea `backend/.env` (no se sube a Git) con la
configuración de MySQL y el origen permitido:

```dotenv
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DATABASE=mirai_games
MYSQL_USER=tu_usuario_mysql
MYSQL_PASSWORD=tu_contrasena_mysql
APP_ORIGIN=http://127.0.0.1:5173
```

Sustituye los valores de usuario y contraseña por los de tu entorno local; no
uses credenciales reales en archivos versionados. También puedes configurar
estas variables en el entorno del sistema, que tiene prioridad sobre `.env`.
Desde la raíz del repositorio, inicia PHP:

```powershell
php -S 127.0.0.1:8000 -t backend backend/index.php
```

No guardes las credenciales en archivos versionados. En otra terminal, comprueba
la API con `Invoke-RestMethod http://127.0.0.1:8000/api/health` o abre esa URL
en el navegador. Debe devolver `status: ok`, `api: up` y `database: connected`.

La autenticación usa sesiones PHP y cookies `HttpOnly`. Para probar el frontend,
inicia Vite en otra terminal dentro de `frontend/` y abre
`http://127.0.0.1:5173` (el origen debe coincidir con `APP_ORIGIN`). Las rutas de autenticación son `/api/auth/csrf`, `/api/auth/register`,
`/api/auth/login`, `/api/auth/me` y `/api/auth/logout`. Las solicitudes que
modifican datos requieren el token CSRF obtenido en la primera ruta y un usuario
autenticado cuando se trata del carrito.

### Backend con una base de pruebas independiente

El backend normal carga `backend/.env`; el cargador conserva las variables que
ya existian en el proceso, por lo que las variables del proceso prevalecen.
Para un proceso de pruebas, inicia PHP desde una terminal PowerShell separada y
define en esa terminal `APP_ENV=test` y `MYSQL_DATABASE=mirai_games_test`.
Cuando `APP_ENV` vale exactamente `test`, `databaseConnection()` exige que
`MYSQL_DATABASE` exista y sea exactamente `mirai_games_test`; de lo contrario,
lanza una excepcion antes de abrir una conexion. En este modo nunca aplica el
valor predeterminado `mirai_games`. `APP_ENV=development` y la ausencia de
`APP_ENV` conservan el modo normal; cualquier otro valor se rechaza antes de
crear PDO.

Configura tambien `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER` y `MYSQL_PASSWORD`
para la instancia de pruebas. No pongas la contrasena en comandos guardados,
scripts ni archivos versionados. Ejecuta este bloque desde la raiz del
repositorio. Calcula y valida esa ruta, restaura las variables que ya existian
en la terminal (incluidas las que estaban sin definir) y solicita la contrasena
de forma segura. No inicies Vite en esta fase; su proxy habitual apunta al
backend normal del puerto 8000.

```powershell
$projectRoot = (Resolve-Path '.').Path
if (-not (Test-Path (Join-Path $projectRoot 'backend\index.php'))) {
    throw 'Ejecuta este bloque desde la raiz del repositorio MiraiGames.'
}

$environmentNames = @(
    'APP_ENV', 'MYSQL_DATABASE', 'MYSQL_HOST', 'MYSQL_PORT',
    'MYSQL_USER', 'MYSQL_PASSWORD', 'APP_ORIGIN'
)
$originalEnvironment = @{}
foreach ($name in $environmentNames) {
    $item = Get-Item -Path "Env:$name" -ErrorAction SilentlyContinue
    $originalEnvironment[$name] = if ($null -eq $item) { $null } else { $item.Value }
}

try {
    $env:APP_ENV = 'test'
    $env:MYSQL_DATABASE = 'mirai_games_test'
    $env:MYSQL_HOST = '127.0.0.1'
    $env:MYSQL_PORT = '3306'
    $env:MYSQL_USER = Read-Host 'Usuario MySQL de pruebas'

    $securePassword = Read-Host 'Contrasena MySQL de pruebas' -AsSecureString
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try {
        $env:MYSQL_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
        $securePassword.Dispose()
    }

    $env:APP_ORIGIN = 'http://127.0.0.1:5174'
    Set-Location $projectRoot
    php -S 127.0.0.1:8001 -t backend backend/index.php
} finally {
    foreach ($name in $environmentNames) {
        if ($null -eq $originalEnvironment[$name]) {
            Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue
        } else {
            Set-Item -Path "Env:$name" -Value $originalEnvironment[$name]
        }
    }
}
```

Antes de usarlo, prepara `mirai_games_test` por separado con el esquema
necesario. No ejecutes migraciones ni pruebas contra `mirai_games`. El guard
del backend impide que este proceso use esa base aunque `backend/.env` la
defina o aunque falte `MYSQL_DATABASE`. En `APP_ENV=test` los endpoints que
inician o reanudan Checkout se rechazan antes de crear pedidos o reservas; el
cliente Stripe tambien queda bloqueado, lo que impide llamadas externas desde
el recuperador. El webhook no se ha modificado. El puerto `8001` distingue el
servidor de pruebas del servidor normal.

## Stripe Checkout en modo de pruebas

El backend usa el SDK oficial `stripe/stripe-php`. En `backend/`, instala las
dependencias con `composer install`. La CLI de PHP necesita habilitados
`curl`, `mbstring` y `openssl`; para instalar los paquetes dist necesita
`zip` o una herramienta `unzip`/7-Zip. El SDK se declara en `backend/composer.json` y las
versiones quedan fijadas en `backend/composer.lock`. No se sube `backend/vendor/`.

Crea `backend/.env` a partir de `.env.example` y configura, además de las
variables MySQL y `APP_ORIGIN`, las claves de una cuenta Stripe de pruebas:

```dotenv
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Las claves Stripe se leen directamente desde `backend/.env`; no se envían al
navegador. No pongas claves reales ni de prueba en archivos versionados. El
backend solo acepta claves `sk_test_` y eventos con `livemode=false`.

### Migración de la base de datos

No ejecutes el flujo de pedidos antes de aplicar
`database/migrations/20261020_add_checkout_reservations.sql`. La migración no se
ejecuta automáticamente. Antes de aplicarla:

1. Detén temporalmente las escrituras de la aplicación.
2. Haz una copia de seguridad completa. En CMD:

   ```cmd
   mysqldump.exe --user=USUARIO --password --single-transaction mirai_games > mirai_games_antes_checkout.sql
   ```

3. Abre `database/migrations/20261020_add_checkout_reservations.sql` en MySQL
   Workbench, selecciona la base `mirai_games` y ejecuta el script. Como
   alternativa, desde CMD:

   ```cmd
   mysql.exe --user=USUARIO --password mirai_games < "C:\miraigames\MiraiGaes\database\migrations\20261020_add_checkout_reservations.sql"
   ```

4. Verifica las columnas e índices con `SHOW COLUMNS FROM orders;`,
   `SHOW INDEX FROM orders;` y `SHOW CREATE TABLE stripe_webhook_events;`. No
   repitas la migración: MySQL devolverá error si las columnas o tabla existen.

La migración solo añade las columnas `checkout_idempotency_key`,
`reservation_expires_at`, `inventory_status`, sus índices y la tabla de eventos.
No borra ni actualiza usuarios, juegos, carritos o pedidos existentes.

### Probar checkout y webhook

1. Con la migración aplicada, Composer instalado y las claves de prueba en
   `backend/.env`, inicia PHP y Vite según los pasos anteriores.
2. En otra terminal, inicia Stripe CLI conectado a tu cuenta de pruebas:

   ```powershell
   stripe listen --forward-to http://127.0.0.1:8000/api/webhooks/stripe
   ```

   Copia el `whsec_...` que muestra Stripe CLI a `STRIPE_WEBHOOK_SECRET` en
   `backend/.env` y reinicia PHP. No uses el secreto de webhook de producción.
3. Regístrate o inicia sesión, añade un juego disponible y usa **FINALIZAR
   COMPRA**. La aplicación reserva stock y redirige a Stripe Checkout.
4. Completa el pago en Stripe con el número de tarjeta de prueba `4242 4242
   4242 4242`, una fecha futura y cualquier CVC. La página de retorno consulta
   `GET /api/orders/{id}`; solo el webhook firmado marca el pedido como pagado.
5. Revisa `/orders` y comprueba que el estado y el total corresponden al pedido.
   No se han probado cobros reales. Para probar firma inválida o eventos
   repetidos, usa Stripe CLI y confirma que el endpoint rechaza firmas inválidas
   y devuelve una respuesta idempotente al mismo `event_id`.

Endpoints añadidos:

- `POST /api/checkout/session`: crea un pedido desde el carrito con el header
  `Idempotency-Key`, reserva stock y devuelve la URL de Checkout.
- `POST /api/orders/{id}/checkout-session`: permite al propietario reanudar un
  checkout pendiente.
- `GET /api/orders` y `GET /api/orders/{id}`: historial y detalle privados del
  usuario autenticado.
- `POST /api/webhooks/stripe`: verifica la firma de Stripe sobre el cuerpo
  original. Es la única ruta exenta de CSRF/origen; no usa sesión.

### Recuperación periódica en Windows

El script `backend/scripts/recoverPendingOrders.php` busca reservas vencidas.
Para sesiones conocidas, solicita su expiración a Stripe y solo devuelve el
stock después de que Stripe confirme `status=expired`. Para una sesión que pudo
crearse pero cuyo ID no llegó a guardarse, reutiliza la idempotencia de Stripe,
asocia la sesión y la expira; las reservas duran 20 horas para que esa
reconciliación permanezca dentro de la ventana mínima de idempotencia de Stripe.
Si el estado remoto sigue siendo incierto, no modifica el stock y registra un
aviso sin datos secretos.

En el Programador de tareas de Windows, crea una tarea que ejecute cada cinco
minutos:

- Programa: `C:\php\php.exe`
- Argumentos: `-f "C:\miraigames\MiraiGaes\backend\scripts\recoverPendingOrders.php"`

Revisa el código de salida y el registro de errores de PHP. El script requiere
la migración, las extensiones PHP del SDK y claves de test válidas.
