# VotaCrudGenerator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/votapil/votacrudgenerator.svg?style=flat-square)](https://packagist.org/packages/votapil/votacrudgenerator)
[![Total Downloads](https://img.shields.io/packagist/dt/votapil/votacrudgenerator.svg?style=flat-square)](https://packagist.org/packages/votapil/votacrudgenerator)

**Generate production-ready CRUD scaffolding from your existing database tables** for Laravel 11 & 12. Introspects your DB schema and generates Models, API Controllers, FormRequests, Resources, Policies, and Factories — all following modern Laravel best practices.

## Installation

```bash
composer require votapil/votacrudgenerator --dev
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag="votacrudgenerator-config"
```

## Quick Start

```bash
# Generate full CRUD for the "posts" table
php artisan vota:crud Post
```

This creates:
- `app/Models/Post.php` — with fillable, casts, SoftDeletes, relationships
- `app/Http/Controllers/PostController.php` — API controller
- `app/Http/Requests/PostStoreRequest.php` — validation from DB types
- `app/Http/Requests/PostUpdateRequest.php` — partial update rules
- `app/Http/Resources/PostResource.php` — JSON resource
- `app/Policies/PostPolicy.php` — authorization
- `database/factories/PostFactory.php` — smart faker
- Route added to `routes/api.php`

> **Note:** Laravel 11+ does not include `routes/api.php` by default. Run `php artisan install:api` first to enable API routing, then use the generator.

## Usage

```bash
php artisan vota:crud {ModelName} [options]
```

Model name should be **singular PascalCase**. Table name is derived automatically (`Post` → `posts`, `BlogPost` → `blog_posts`).

### Options

| Option | Description | Example |
|--------|-------------|---------|
| `--path=` | Sub-path for namespace grouping | `--path=Blog` → `App\Models\Blog\Post` |
| `--namespace=` | Override base model namespace | `--namespace="App\Models\Admin"` |
| `--table=` | Explicit table name (skip convention) | `--table=wp_posts` |
| `--no-policy` | Skip Policy generation | |
| `--no-request` | Skip FormRequest generation | |
| `--no-resource` | Skip Resource generation | |
| `--no-factory` | Skip Factory generation | |
| `--no-route` | Skip route injection | |
| `--force` | Overwrite existing files | |

### Examples

```bash
# Grouped namespace
php artisan vota:crud Post --path=Blog

# Custom namespace
php artisan vota:crud Post --namespace="App\Models\Admin"

# Non-standard table name
php artisan vota:crud Post --table=legacy_blog_posts

# Only Model + Controller
php artisan vota:crud Post --no-policy --no-request --no-resource --no-factory --no-route

# Regenerate (overwrite)
php artisan vota:crud Post --force
```

### Customize Stubs

```bash
php artisan vota:stubs
```

Publishes stubs to `stubs/vendor/votacrud/` for editing. The generator uses your custom stubs when available.

---

## Why This Approach?

### Database-First Development

Most CRUD generators work top-down: define fields in config, generate migrations. **VotaCrudGenerator works bottom-up**: your database is the single source of truth.

This is powerful when:
- Working with an **existing database** (legacy systems, shared DBs, DBA-designed schemas)
- Preferring **migrations-first** workflow — scaffold _after_ the schema is ready
- Onboarding onto a project — quickly scaffold models for dozens of tables

### Smart Schema Analysis

The generator doesn't just read column names — it understands your schema:

| Detection | What Happens |
|-----------|-------------|
| `deleted_at` column | Adds `SoftDeletes` trait + `restore()` / `forceDelete()` endpoints |
| Foreign key constraints | Auto-generates `belongsTo()` and `hasMany()` relationships |
| Column types | Maps to `$casts`, validation rules, and Faker methods |
| Unique indexes | Adds `unique` validation rule |
| Nullable columns | `nullable` instead of `required` in validation |
| Column names (email, phone…) | Picks appropriate Faker methods |

### Advantages Over Manual Scaffolding

- **Zero typos** — field names, types, relationships come directly from the DB
- **Consistency** — every generated file follows the same conventions
- **Speed** — scaffold a complete CRUD in seconds
- **Best Practices** — typed returns, `validated()` over `$request->all()`, proper HTTP status codes
- **Customizable** — publish and modify stubs to match your project style

---

## Generated Files in Detail

### Model
- `$fillable` from non-system columns
- `casts()` method from column types (json → `array`, bool → `boolean`, etc.)
- `SoftDeletes` trait when `deleted_at` detected
- `belongsTo()` / `hasMany()` from foreign keys
- `HasFactory` trait included

### Controller (API)
- RESTful: `index`, `store`, `show`, `update`, `destroy`
- Uses `$request->validated()` for security
- Proper HTTP status codes (`201` create, `204` delete)
- Eager loading via `?with=relation1,relation2`
- SoftDeletes: adds `restore()` and `forceDestroy()`

### FormRequests
- **Store**: rules mapped from DB types (`integer`, `string|max:255`, `nullable`, `date`)
- **Update**: same with `sometimes` prefix for partial updates
- Unique constraints from DB indexes included

### Resource
- All columns mapped, `JsonResource` with typed `toArray(Request $request): array`

### Policy
- Standard methods: `viewAny`, `view`, `create`, `update`, `delete`
- `restore` / `forceDelete` when SoftDeletes detected
- `App\Models\User`, no deprecated `HandlesAuthorization` trait
- Auto-discovered by Laravel 11/12

### Factory
- Faker by column name (`email` → `safeEmail()`, `name` → `name()`)
- Falls back to type-based (`integer` → `randomNumber()`)

### Routes

Auto-appends to `routes/api.php`:
```php
Route::apiResource('posts', \App\Http\Controllers\PostController::class);
// + restore/forceDestroy routes when SoftDeletes detected
```

> **Note:** Laravel 11+ requires `php artisan install:api` before `routes/api.php` exists. If the file is missing, the generator prints the route snippet to the console instead of failing.

---

## Customization Guide

### Stub Placeholders

**Simple placeholders:**
```
{{ modelName }}        → Post
{{ modelVariable }}    → post
{{ modelNamespace }}   → App\Models
{{ fillable }}         → ['title', 'body', ...]
{{ validationRules }}  → ['title' => 'required|string|max:255', ...]
```

**Conditional blocks:**
```
{{#if softDeletes}}
use Illuminate\Database\Eloquent\SoftDeletes;
{{/if softDeletes}}
```

### Configuration

All defaults in `config/votacrudgenerator.php`:

```php
return [
    'namespaces' => [
        'model'      => 'App\\Models',
        'controller' => 'App\\Http\\Controllers',
        'request'    => 'App\\Http\\Requests',
        'resource'   => 'App\\Http\\Resources',
        'policy'     => 'App\\Policies',
    ],
    'generate' => [
        'model' => true, 'controller' => true,
        'store_request' => true, 'update_request' => true,
        'resource' => true, 'policy' => true,
        'factory' => true, 'routes' => true,
    ],
    'detect' => [
        'soft_deletes' => true, 'timestamps' => true,
        'relationships' => true, 'casts' => true,
    ],
    'route_file'   => 'routes/api.php',
    'route_prefix' => '',
    'packages' => [
        'spatie_query_builder' => false,
    ],
];
```

### Extending the Generator

- **Custom validation rules**: Override `getColumnValidationRule()` in a custom `DatabaseIntrospector` bound via the service container
- **Custom faker methods**: Override `getColumnFaker()` for project-specific patterns
- **New file types**: Create a stub in `stubs/vendor/votacrud/` and extend `CrudGenerateCommand`

---

## 🤖 AI Skills / Cursor Rules

If you use AI coding assistants like **Cursor**, **GitHub Copilot**, or **Antigravity**, you can train them to use `VotaCrudGenerator` instead of writing manual, error-prone CRUD boilerplate. 

Because the generator extracts your **Database Column Comments** into Model PHPDocs and Resource fields, your AI will instantly understand the business context of every auto-generated field.

Add the following block to your project's `.cursorrules`, GitHub Copilot instructions, or AI agent context (e.g., `skills/votacrud.md`):

```markdown
# Laravel CRUD Generation

When asked to create a new CRUD, resource, or model with its related files:
1. DO NOT write the Model, Controller, Requests, Resource, Policy, or Factory manually.
2. Ask the user to create and run the database migration first. Ensure you add `->comment(...)` to the columns to provide business context.
3. Once the database table is ready, use the VotaCrudGenerator package by running:
   `php artisan vota:crud {ModelName}`
4. The package will automatically introspect the database and generate all necessary files based on the concrete table schema (columns, types, foreign keys, soft deletes, and comments).
5. Only make manual edits to the generated files if specific custom business logic is requested.
```

---

## Multi-Database Support

Works with any Laravel-supported driver via the `Schema` facade:

| Database | Columns | Foreign Keys | Indexes |
|----------|---------|-------------|---------|
| MySQL | ✅ | ✅ | ✅ |
| PostgreSQL | ✅ | ✅ | ✅ |
| SQLite | ✅ | ✅ | ✅ |

## Requirements

- PHP 8.3+
- Laravel 11.x or 12.x
- Existing database with tables

## License

MIT — see [LICENSE.md](LICENSE.md)
