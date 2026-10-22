# Byfall CRUD

**Byfall CRUD** is a Laravel package that generates a complete REST API CRUD
from your database schema or migrations.

It helps you rapidly scaffold:
- Models
- Repositories
- Form Requests (Store / Update)
- Controllers (API only)
- API Resources & Collections
- Factories
- Seeders
- Postman API collections (JSON)

> Byfall CRUD V2 is being developed incrementally without breaking the historical commands.

## Compatibility

### Officially supported

| Laravel | PHP | Status |
|---|---|---|
| 12 | 8.2–8.5 | Supported and verified by CI |
| 13 | 8.3–8.5 | Supported and verified by CI |

### Legacy / unsupported

| Laravel | Status |
|---|---|
| 10 | Legacy; not tested or guaranteed by the V2 CI |
| 11 | Legacy; not tested or guaranteed by the V2 CI |

Laravel 10 and 11 are outside their official security-support windows. Composer
currently blocks affected dependency resolutions because of security advisories;
this is not a Byfall CRUD defect and the security protection is not bypassed.
Compatibility is claimed only for combinations that complete the full CI job.

---

## ✨ Features

- 🚀 Generate full API CRUD in one command
- 🧠 Infer fields, validation rules, casts and relations
- 🗄️ Source from **database** or **migration files**
- 🧪 Ready-to-use FormRequests (store & update)
- 🧱 Repository pattern
- 🔎 Allowlisted search, filters, sorting, includes and pagination
- 📦 API Resources & Collections
- 🌱 Factories and Seeders
- 📬 Postman collection generation
- 🧹 Safe deletion of generated files
- 🔮 Explicit compatibility with the Laravel majors verified by CI

---

## 📋 Requirements

- Laravel 12 with PHP 8.2–8.5
- Laravel 13 with PHP 8.3–8.5

---

## 📦 Installation

```bash
composer require byfallcode/byfall-crud
php artisan byfall:install
php artisan make:entity Product
```
---

Laravel automatically discovers the package service provider, which only exposes
the Artisan commands. `byfall:install` publishes two autonomous Laravel classes:

```text
app/Support/ApiResponse.php
app/Support/ApiErrorCode.php
```

It never modifies `bootstrap/app.php`, `bootstrap/providers.php`, middleware groups
or the global exception handler. Existing files are preserved. Use `--force` only
when you intentionally want to replace these two published classes. Repeated
installation is safe and idempotent.

### Migrating from the legacy API Foundation

Earlier releases could publish `ByfallApiServiceProvider`, `ForceJsonResponse` and
`ApiExceptionRenderer`, then register the provider in `bootstrap/providers.php`.
The installer now detects those elements and prints migration guidance without
changing or deleting them.

To migrate safely:

1. update Byfall CRUD and run `php artisan byfall:install`;
2. inspect the three legacy files for application-specific customizations;
3. remove `App\Providers\ByfallApiServiceProvider::class` from
   `bootstrap/providers.php` if it is still registered;
4. remove the legacy files only after confirming their behavior is no longer needed;
5. run `php artisan optimize:clear`.

Never delete customized application files blindly. The current `--force` option
updates only `ApiResponse.php` and `ApiErrorCode.php`; it does not clean up legacy
artifacts.

## 🚀 Available Artisan Commands

byfall:install – Install the API response, middleware and exception foundation

make:entity – Generate a complete API CRUD

delete:entity – Remove generated files

make:api-collection – Generate Postman collections

---

## 🚀Arguments
Argument	Description
name	Entity name in StudlyCase (e.g. Post, Category, UserProfile)
Options
Option	Description
-  --source=db	Infer entity from the database schema (default)
- --source=migration	Infer entity from a migration file
- --table=	Database table name (only when --source=db)
- --migration=	Path to the migration file (required with --source=migration)
- --no-resources	Skip API Resources & Collections
- --no-factory	Skip Factory generation
- --no-seeder	Skip Seeder generation
- --no-collection-json	Skip Postman collection generation
- --force	Overwrite existing files without confirmation

---

### Examples

#### 1️⃣ Generate an entity from the database (default)

`php artisan make:entity Category --source=db`

-   Uses table `categories` by default

-   Automatically infers:

    -   columns

    -   nullable / required fields

    -   unique constraints

    -   foreign keys (`belongsTo`)

    -   validation rules

    -   casts

    -   soft deletes


* * *

#### 2️⃣ Generate an entity from the database with a custom table name

`php artisan make:entity Category --source=db --table=product_categories`

* * *

#### 3️⃣ Generate an entity from a migration file

`php artisan make:entity Category  --source=migration  --migration="database/migrations/2026_01_04_210315_create_categories_table.php"`

Useful when:

-   the database is not migrated yet

-   you want to scaffold before deployment


* * *

#### 4️⃣ Generate an entity without optional components

`php artisan make:entity Category \   --no-resources \   --no-factory \   --no-seeder \   --no-collection-json`

* * *

#### 5️⃣ Force regeneration of an entity

`php artisan make:entity Category --force`

⚠️ This will overwrite all previously generated files.

* * *

### API Route (manual step)

After generating an entity, register the API route manually:

`use App\Http\Controllers\CategoryController;  Route::apiResource('categories', CategoryController::class);`

### Safe query API

When the API foundation is installed, `make:entity` generates autonomous query
classes under `App\Queries`. Their allowlists come exclusively from the inferred
entity metadata; request input can never select an arbitrary SQL column.

```http
GET /api/products?search=phone&category_id=4&active=true&sort=price&direction=desc&include=category&per_page=20
```

- `search` applies a bound `LIKE` value to searchable columns. Case sensitivity is
  determined by the database collation; no database-specific `ILIKE` is emitted.
- simple filters are accepted only for filterable columns. Boolean filters accept
  `true`, `false`, `1`, or `0`.
- `sort` must name a sortable column and `direction` must be `asc` or `desc`.
- `include` is a comma-separated list of explicitly allowed, non-nested relations.
- `per_page` defaults to 15 and is constrained to 1–100.

Unknown columns, unsupported includes, invalid booleans and unsafe sort values are
ignored. Pagination links retain only the normalized, accepted query parameters.

* * *

## 🧹 delete:entity — Usage

Remove all files generated by `make:entity`.

`php artisan delete:entity Category`

With confirmation for each file.

Force deletion (no confirmation):

`php artisan delete:entity Category --force`

This command removes:

-   Model

-   Repository

-   Query specification, parser and applier

-   Controller

-   Form Requests

-   API Resources & Collections

-   Factory

-   Seeder

-   Postman collection


* * *

## 📬 make:api-collection — Usage

Generate a Postman collection for API resources.

### From database (default)

`php artisan make:api-collection`

### From migrations

`php artisan make:api-collection --source=migrations`
