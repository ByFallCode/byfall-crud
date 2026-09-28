# Byfall CRUD V2 architecture

## Current architecture

Byfall CRUD exposes three historical Artisan commands. In V2, `MakeEntity` and
`MakeApiCollection` share the Smart Core for schema observation and metadata
inference. Since V2-L04, model, form-request and resource rendering is delegated to
focused Smart Generators; legacy repository, controller, factory, seeder, resource
collection and Postman rendering remain in the command temporarily. The migration
is incremental: existing commands and generated Laravel code remain the public
contract while internal responsibilities move behind typed data.

## Smart Core objective

The Smart Core will analyze a database or migration set once and produce a shared
`EntityMetadata` representation. Model, request, resource, controller, test,
Postman and future OpenAPI generators will consume that representation instead of
inspecting the source independently.

```text
Database / migrations -> SchemaAnalyzer -> EntityMetadata -> generators
```

V2-L01 introduced the metadata boundary and characterized historical behavior.
V2-L02 implements the analyzer pipeline described below.

## Smart Core schema pipeline

`SchemaSource` is the acquisition boundary. `DatabaseSchemaSource` selects one
driver-specific inspector (`MySqlSchemaInspector` or `PostgreSqlSchemaInspector`),
while `MigrationSchemaSource` preserves the supported historical migration syntax.
Both produce native `EntityMetadata` objects.

`EntitySchemaAnalyzer` is the single entry point used by `MakeEntity` and
`MakeApiCollection`. Its command-scoped cache guarantees that the same identifier
and source instance are inspected only once:

```text
Database connection -> driver inspector --+
                                          +-> EntitySchemaAnalyzer -> EntityMetadata
Migration file -> MigrationSchemaSource --+
```

The commands contain no SQL schema inspection and no migration parsing. Legacy
generators still receive associative arrays through `LegacyMetadataAdapter`; those
arrays are compatibility output, not the source of truth.

Database inspectors preserve columns, database and normalized types, nullability,
defaults when exposed by the engine, lengths and numeric dimensions, unsigned and
generated flags, primary keys, foreign keys, unique constraints and indexes.
Composite unique constraints remain grouped. Migration parsing deliberately rejects
recognized unsupported constructs instead of silently inventing metadata.

## Metadata Intelligence

V2-L03 makes the separation between observation and decisions explicit:

```text
Schema facts -> raw EntityMetadata -> MetadataEnricher -> enriched EntityMetadata -> generation
```

Schema sources populate only immutable facts. `MetadataEnricher` returns a new
`EntityMetadata`; raw values are never mutated. Its order is explicit: fillable and
relationships are inferred first, validation consumes the fillable decision, query
capabilities consume reliable relationships, and all results are copied into the
enriched value.

- `CastInferrer` selects Laravel casts and keeps decimals precise with `decimal:n`.
- `FillableInferrer` excludes engine-managed fields without confusing writable and hidden.
- `SensitiveFieldPolicy` and `HiddenFieldInferrer` classify explicit secret conventions.
- `ValidationRuleInferrer` handles defaults, nullability, simple uniqueness and real FKs.
- `RelationshipInferrer` creates only `belongsTo` metadata backed by real constraints.
- `QueryCapabilityInferrer` conservatively proposes search, filter, sort and include fields.

Composite uniqueness and ambiguous relationship names remain represented and emit
lightweight diagnostics rather than false rules. No HTTP behavior consumes query
capabilities yet. `LegacyRuleBuilder` was removed: validation has one inference
engine, while `LegacyMetadataAdapter` only translates enriched metadata for existing
generators.

## Smart Generators

V2-L04 introduces a rendering boundary after Metadata Intelligence:

```text
Enriched EntityMetadata + GenerationContext
                    -> Smart Generator -> PHP source -> SafeFileWriter -> application
```

`ModelGenerator`, `StoreRequestGenerator`, `UpdateRequestGenerator` and
`ResourceGenerator` consume enriched metadata directly. They do not inspect a
database, parse migrations or repeat inference. `GenerationContext` contains only
generation facts that do not belong to the schema, including namespaces, entity
name and an optional route parameter. The generators return source code and never
write files themselves.

The model renderer uses metadata for table, primary-key configuration, fillable,
hidden, modern `casts()`, timestamps, soft deletes and reliable `belongsTo`
relations. Ambiguous relations are deliberately skipped. Decimal casts retain their
scale. Requests render the inferred store and update rules; simple unique updates
use `Rule::unique()->ignore()` only when the caller supplies an explicit route
parameter. Without that context, the generator does not guess one.

Resources expose observed columns except those in `EntityMetadata.hidden`. Reliable
relations use `whenLoaded()` and are returned without assuming that a related
Resource class exists. This avoids implicit relationship loading and keeps generated
code independent from Byfall CRUD at runtime.

`SafeFileWriter` creates missing directories but refuses to replace an existing
file unless the existing `--force` option is enabled. Dynamic PHP rendering remains
programmatic: the conditional sections are clearer without a custom template engine
or a large family of stubs. Repository, controller, factory, seeder, collection and
Postman generation remain legacy and continue through `LegacyMetadataAdapter`.

Intentional V2-L04 output changes are limited to the four Smart Generator artifacts:

- models can now include hidden fields, precise casts, key/timestamp configuration,
  soft deletes and reliable typed relationships;
- request rules are rendered as readable arrays and update uniqueness uses Laravel's
  structured `Rule` API with explicit route context;
- resources exclude hidden columns and expose reliable relationships only through
  `whenLoaded()`.

No historical snapshot required an update. Controller output and all out-of-scope
legacy artifacts retain their characterized behavior.

## API Foundation

V2-L05 defines a small, predictable HTTP contract without changing generated
controllers yet:

```text
Request -> ForceJsonResponse -> Laravel -> ApiResponse
Exception -> ApiExceptionRenderer -> ApiResponse
```

`ForceJsonResponse` changes the `Accept` header only for `api/*` requests so Laravel
correctly selects JSON validation and exception behavior. It never overwrites a
response `Content-Type`; a plain-text or HTML response is therefore never mislabeled
as JSON. Web requests are left untouched.

`ApiResponse` provides `success`, `created`, `noContent`, `error` and `paginated`.
Success payloads contain `success`, `message`, `data` and optional `meta`. Errors
contain `success`, `message`, a centralized `ApiErrorCode`, and optional `errors`.
A 204 response has an empty body. Pagination exposes stable scalar metadata and
keeps navigational URLs in a separate `links` object:

```text
meta: current_page, per_page, last_page, total, from, to
links: first, last, prev, next
```

`ApiExceptionRenderer` maps Laravel validation, authentication, authorization,
model-not-found and throttle exceptions plus Symfony endpoint and method exceptions.
Rate-limit headers are preserved. It returns `null` for non-API requests so Laravel's
normal web exception flow remains responsible. Unexpected production errors expose
only `INTERNAL_SERVER_ERROR`; messages, stack traces, paths, SQL and other internal
details are omitted. Debug behavior is controlled by an explicit boolean intended to
come from `config('app.debug')`, never from `APP_ENV` alone, and still excludes stack,
file and line data.

### Installation decision

Two approaches were evaluated. Automatic runtime registration from the package is
simple but silently couples every generated application to Byfall CRUD and can alter
unrelated HTTP behavior. Rewriting `bootstrap/app.php` automatically would be brittle
across valid Laravel 12/13 application customizations. V2-L05 therefore chooses the
limited internal-infrastructure option:

- the foundation is implemented and tested in the package but is not globally
  registered by its service provider;
- `bootstrap/app.php` is not modified;
- no configuration file is introduced because the API prefix, debug flag and
  pagination values already have clear Laravel sources;
- a future installer may publish equivalent standard Laravel classes into `App\...`
  and add an explicit, structure-aware bootstrap integration.

There is no automated installation operation in L05, so there is no repeatable write
that could duplicate middleware or exception hooks. Idempotence becomes mandatory
when the public installer is introduced. Until then, consumers integrating the
foundation manually must register `ForceJsonResponse` for the API middleware group
and delegate API render callbacks to `ApiExceptionRenderer`, passing
`(bool) config('app.debug')`. This choice is explicit and avoids claiming that a
fragile bootstrap rewrite is safe.

## API installation and Smart Controllers

V2-L06 makes the L05 foundation installable with `php artisan byfall:install`.
Installation publishes autonomous application code to `App\Support`,
`App\Http\Middleware` and `App\Providers`; published files contain no
`ByfallCode\ByfallCrud` runtime reference. A generated application can therefore
retain the installed foundation after removing the generator package.

The installer deliberately leaves `bootstrap/app.php` untouched. For a standard
Laravel 12/13 `bootstrap/providers.php`, it uses Laravel's own
`ServiceProvider::addProviderToBootstrapFile()` API to register
`App\Providers\ByfallApiServiceProvider`. Before calling that API, it recognizes a
conservative static provider-array shape. Custom or dynamic files are never
rewritten: the command reports a partial installation, exits unsuccessfully and
prints the exact provider line for manual addition. The published provider adds
`ForceJsonResponse` to the API middleware group and delegates API exception
rendering to the installed renderer. Web exceptions continue through Laravel.

Publication uses `SafeFileWriter`. Existing application files are preserved by
default; `--force` replaces only the known published classes. It never authorizes an
unsafe bootstrap mutation. Repeated installation preserves file contents and the
framework registration helper de-duplicates and sorts providers.

`ControllerGenerator` completes the Smart Generator set for the current CRUD
surface. It consumes `EntityMetadata` and `GenerationContext`, preserves the legacy
Repository contract, uses validated FormRequest input, resources when enabled,
`ApiResponse::paginated`, `created`, `success` and a genuine 204 `noContent`.
The route parameter is shared with `UpdateRequestGenerator`. If the API Foundation
is absent, `make:entity` emits an explicit warning and generates the characterized
legacy controller instead of producing a broken class. Search, filtering, sorting
and includes remain outside L06.

## Metadata roles

- `EntityMetadata` is the normalized entity boundary. In V2-L01 it carries every
  value required by the legacy generators and reserves explicit fields for later
  relationships and query capabilities.
- `ColumnMetadata` stores facts about a column. Facts such as database type,
  nullability and defaults remain distinct from inferred decisions such as casts.
- `ForeignKeyMetadata` identifies the local column and referenced table/column.
- `UniqueConstraintMetadata` models a group of columns so V2 can represent both
  simple and composite uniqueness.
- `IndexMetadata` models indexed columns and the primary/unique characteristics.

These value objects are immutable, framework-independent PHP objects. They contain
no rendering or filesystem logic.

## LegacyMetadataAdapter

`LegacyMetadataAdapter` is the compatibility bridge between the associative
metadata arrays used by V1 and `EntityMetadata`.

```text
legacy analysis -> EntityMetadata -> legacy adapter -> legacy generators
```

V2-L01 exercises this path in `make:entity`. The round trip is tested so later
analyzer work can proceed without rewriting all generators at once.

## TypeMapper

`TypeMapper` centralizes Blueprint-method-to-SQL and SQL-type normalization.
`CastInferrer` separately decides Laravel casts. The historical `sqlTypeToCast`
method remains only for backwards-compatible callers and tests; schema sources no
longer use it as their normalized type.

## Compatibility policy

Byfall CRUD V2 targets Laravel versions that remain under official security
maintenance. Older versions may continue to work, but they are neither guaranteed
nor tested by the V2 CI.

The target matrix is:

| Laravel | Minimum PHP |
|---|---|
| 12 | 8.2 |
| 13 | 8.3 |

The V2-L01 compatibility workflow verified Laravel 12 on PHP 8.2 and Laravel 13
on PHP 8.3 and PHP 8.5. Each job resolved its dependencies independently, passed
the platform check and completed the full 30-test suite.

Support claims must be backed by CI or an explicitly recorded local test. Existing
commands and options remain compatible. A behavior change requires a documented
bug fix, an opt-in profile, or a migration path.

Composer explicitly declares Laravel 12 and 13; it does not implicitly claim future
Laravel majors. Laravel 10 and 11 are legacy and unsupported by the V2 policy because
their official security-support windows have ended and affected dependency
resolutions are blocked by Composer security advisories. This is not a package bug,
and the CI does not disable Composer's security protections.

Compatibility is tested separately from the Composer declaration. The GitHub Actions workflow resolves every matrix entry from
its own PHP runtime after removing the development lock's `config.platform.php`
override inside the ephemeral job only. It then reports installed versions, checks
platform requirements and runs the complete suite. Adding the workflow does not by
itself prove that a matrix entry passes; only an observed successful CI run does.

The development lock is resolved for PHP 8.2 and Laravel 12. It is useful for local
reproducibility but does not constitute execution proof for another PHP or Laravel
combination.

## Laravel first

Generated code uses Laravel models, requests, controllers, resources, factories
and routing conventions. Byfall CRUD should prefer native framework mechanisms to
package-specific runtime abstractions.

## Generated code belongs to the developer

Generated files are application code. They may be edited, moved or deleted by the
developer. Generation must not overwrite them silently, and future generation
plans must expose conflicts before writing.

## No runtime lock-in

CRUD code generated by the package must continue to operate when Byfall CRUD is
removed from the application. Features that necessarily add runtime behavior must
remain optional and be clearly documented.

## Progressive migration

1. Characterize V1 output and fix only approved critical bugs.
2. Introduce typed metadata and adapters.
3. Extract database and migration schema sources in V2-L02.
4. Add metadata intelligence without changing HTTP behavior (V2-L03).
5. Move model, request and resource rendering into focused Smart Generators (V2-L04).
6. Add optional API modules only after the core and generators are stable.

This sequence avoids a big-bang rewrite and keeps each change reviewable.
