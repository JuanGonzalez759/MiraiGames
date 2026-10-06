# MiraiGames

Proyecto de tienda online de videojuegos para el proyecto final de DAW.

## Tecnologías

- Frontend: React y Vite (JavaScript).
- Backend: PHP para la futura API REST.
- Base de datos: MySQL.

## Estructura

- `frontend/`: aplicación React.
- `backend/`: esqueleto del backend PHP.
- `database/`: scripts SQL; el esquema se definirá en la siguiente fase.
- `docs/`: documentación adicional.
- `tests/`: pruebas del proyecto.

## Requisitos

- Node.js LTS compatible con Vite.
- PHP 8.x con PDO y `pdo_mysql` para las fases de backend.
- MySQL 8.x para las fases de base de datos.

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
El backend todavía no está implementado.

## Estado

Configuración inicial. Aún no hay API, esquema SQL ni funcionalidades de tienda.
