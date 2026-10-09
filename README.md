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
- PHP 8.x con las extensiones `PDO` y `pdo_mysql`.
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
El backend responde al endpoint de comprobación `GET /api/health`.

## Desarrollo del backend

Desde la raíz del repositorio, configura las variables de entorno en PowerShell:

```powershell
$env:MYSQL_HOST = "127.0.0.1"
$env:MYSQL_PORT = "3306"
$env:MYSQL_DATABASE = "mirai_games"
$env:MYSQL_USER = "tu_usuario_mysql"
$env:MYSQL_PASSWORD = "tu_contrasena_mysql"
php -S 127.0.0.1:8000 -t backend backend/index.php
```

No guardes las credenciales en archivos versionados. En otra terminal, comprueba
la API con `Invoke-RestMethod http://127.0.0.1:8000/api/health` o abre esa URL
en el navegador. Debe devolver `status: ok`, `api: up` y `database: connected`.

El backend aún no implementa cuentas, catálogo, carrito, pedidos ni pagos.
