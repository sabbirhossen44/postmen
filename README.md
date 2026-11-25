# Postmen — API Client (Postman clone)

A full Postman-style API client built with **Laravel 13 + Vue 3 (plain JS) + Tailwind CSS 4**.

Send requests, save them in collections, use environments with `{{variables}}`,
import/export Postman Collection v2.1 files, and keep a request history — all from
one app. Requests are sent through the Laravel backend (Guzzle), so browser CORS
never blocks a call.

## Features

- **Workspaces** — group collections and environments per workspace.
- **Collections, folders, requests** — nested folders, coloured method badges
  (GET / POST / PUT / PATCH / DELETE / HEAD / OPTIONS), duplicate, rename, delete.
- **Request builder** — Params, Authorization (none / Bearer / Basic / API Key),
  Headers, Body (none / form-data / x-www-form-urlencoded / raw JSON / binary file).
- **Environments** — variables with `{{key}}` replacement, one active environment
  per workspace, collection variables as fallback.
- **Send** — status, time, size, pretty JSON / raw body, response headers.
  Private / loopback URLs are refused by the sender (SSRF protection).
- **History** — every sent request is saved, click one to load it back.
- **Import / Export** — Postman Collection v2.1 JSON in and out.
- **Media** — uploads live in the `media` table; anything with an icon or a file
  (user avatar, workspace icon, collection icon, binary body file) points at it
  with a `media_id` column.
- **Auth** — register / login with Laravel Sanctum tokens.

## Stack

| Piece | Version |
| --- | --- |
| PHP | ^8.3 (developed on 8.4) |
| Laravel | ^13.17 (13.44.1 verified) |
| Laravel Sanctum | ^4.0 |
| arafatdev/laravel-repository | ^3.1 (static repositories) |
| Vue | ^3.5, plain JavaScript, Options API — no TypeScript |
| Tailwind CSS | ^4.0 (Vite plugin) |
| Vite | ^8.0, `@vitejs/plugin-vue` ^6 |

## Setup

```bash
git clone https://github.com/sabbirhossen44/postmen.git
cd postmen

composer install
cp .env.example .env
php artisan key:generate

# SQLite is the default (database/database.sqlite). For MySQL, set
# DB_CONNECTION, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD in .env.
php artisan migrate
php artisan storage:link

npm install
npm run dev        # frontend dev server (Vite)
php artisan serve  # backend on http://127.0.0.1:8000
```

Open http://127.0.0.1:8000, register an account, create a workspace and start
sending requests. For a production build: `npm run build`.

## Tests

```bash
php artisan test   # 11 tests, PostmenApiTest covers auth, CRUD, send + SSRF block, import/export, media, history
```

## How the code is organised

```
app/Http/Controllers/Api/   thin controllers, {success, message, data} JSON
app/Http/Requests/          FormRequests (rules + messages)
app/Models/                 $guarded = ['id'], relations, $casts for JSON fields
app/Repositories/           static repositories (arafatdev/laravel-repository):
                            WorkspaceRepository, CollectionRepository, FolderRepository,
                            ApiRequestRepository, EnvironmentRepository,
                            HistoryRepository, MediaRepository, UserRepository
app/Services/               VariableResolver, RequestSender (Guzzle),
                            CollectionImporter, CollectionExporter, MediaService
database/migrations/        media first, then users.media_id, workspaces,
                            collections, folders, requests, environments, histories
resources/js/               Vue 3 app: api.js (axios), store.js (reactive store),
                            utils/variables.js, components/ (App, AuthView, TopBar,
                            Sidebar, RequestBuilder, ResponseViewer, Modals,
                            KeyValueEditor)
routes/api.php              public auth routes, then one auth:sanctum group
routes/web.php              SPA fallback to the Vue app
```

Repositories follow the `arafatdev/laravel-repository` pattern exactly: they are
static (`WorkspaceRepository::find($id)`, `::query()->where(...)->get()`), with
custom intent-named static helpers (`findForUser`, `activeForWorkspace`, …).
One fix was needed for Laravel 13: the package base `delete()` calls
`query()->destroy()`, which no longer exists on Eloquent Builder, so every
repository overrides `delete($id)` with `query()->whereKey($id)->delete()`.

## API quick reference

All routes live under `/api`. Everything except register/login needs
`Authorization: Bearer <token>`.

| Method | Route | What it does |
| --- | --- | --- |
| POST | /auth/register, /auth/login | Sanctum token |
| POST | /auth/logout | Revoke current token |
| GET | /auth/user | Current user |
| CRUD | /workspaces | Workspaces |
| CRUD | /collections (+ ?workspace_id=) | Collections |
| GET | /collections/{id}/export | Download v2.1 JSON |
| POST | /collections/import | Import v2.1 JSON (file or json) |
| POST | /collections/{id}/folders, /folders/{id} | Folders |
| POST | /collections/{id}/requests, /requests/{id} | Saved requests |
| POST | /requests/{id}/duplicate | Duplicate a request |
| CRUD | /environments (+ ?workspace_id=) | Environments |
| POST | /environments/{id}/activate | Set active environment |
| GET/DELETE | /histories | History list / clear / delete one |
| POST | /media | Upload a file (multipart `file`) |
| POST | /send | Send a request, returns status/time/size/headers/body |

## Notes

- `POST /send` resolves `{{variables}}` from the given (or active) environment
  first, then collection variables. History is saved when `save_history` is true.
- Response bodies are capped at 2 MB in storage; the sender times out after 30s.
- `public/build` (Vite output) and `vendor/` / `node_modules/` are not committed;
  run `npm run build` after pulling.
