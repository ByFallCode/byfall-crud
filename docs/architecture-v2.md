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

## Non-invasive API Foundation and Smart Controllers

The API Foundation is generated application support code, not a global runtime
layer:

```text
EntityMetadata -> generators -> Laravel application code
Controller -> ApiResponse
```

`byfall:install` publishes only `App\Support\ApiResponse` and
`App\Support\ApiErrorCode`. It does not publish an application service provider,
install middleware, intercept exceptions or modify either bootstrap file. The
package service provider remains distinct: Laravel package discovery uses it only
to register the package's Artisan commands.

`ApiResponse` provides `success`, `created`, `noContent`, `error` and `paginated`.
Success payloads contain `success`, `message`, `data` and optional `meta`. Explicit
errors contain `success`, `message`, a centralized `ApiErrorCode`, and optional
`errors`. A 204 response has an empty body. Pagination exposes stable scalar
metadata and keeps navigational URLs in a separate `links` object:

```text
meta: current_page, per_page, last_page, total, from, to
links: first, last, prev, next
```

Laravel remains responsible for normal HTTP exception rendering. The generator does
not copy global exception behavior into controllers and does not add a hidden
runtime hook.

Publication uses `SafeFileWriter`. Existing application files are preserved by
default; `--force` replaces only `ApiResponse.php` and `ApiErrorCode.php`. Repeated
installation is idempotent and leaves `bootstrap/app.php` and
`bootstrap/providers.php` byte-for-byte unchanged.

Installations created by earlier releases may still contain
`ByfallApiServiceProvider`, `ForceJsonResponse`, `ApiExceptionRenderer` and a provider
entry in `bootstrap/providers.php`. The installer detects and reports these elements
but never removes or rewrites them. Developers must review local customizations
before removing legacy runtime integration.

`ControllerGenerator` consumes `EntityMetadata` and `GenerationContext`, preserves
the Repository contract, uses validated FormRequest input, resources when enabled,
`ApiResponse::paginated`, `created`, `success` and a genuine 204 `noContent`. The
route parameter is shared with `UpdateRequestGenerator`. If `ApiResponse` is absent,
`make:entity` emits an explicit warning and generates the characterized legacy
controller instead of producing a broken class.

## V2-L07 safe query pipeline

V2-L07 turns the query capabilities inferred in L03 into generated application
code. The request crosses explicit boundaries:

```text
Request -> EntityQueryParser -> EntityQuerySpecification
        -> EntityQueryApplier -> Eloquent Builder -> Paginator
```

The generated parser contains only the `filterable`, `sortable` and
`allowedIncludes` allowlists from `EntityMetadata`; the applier contains only its
`searchable` allowlist. Reserved transport parameters cannot become column
filters. User values are passed through Eloquent bindings, sorting is limited to
an exact allowlist and `asc`/`desc`, nested includes are rejected unless they are
explicitly present in metadata, and pagination is bounded to 1–100.

The controller translates HTTP input into a specification and delegates. The
repository receives no `Request`; it applies the specification and preserves its
normalized parameters on paginator links. The existing find/create/update/delete
contract remains intact. Generated `App\Queries` and repository classes have no
runtime reference to this package.

Invalid and unknown query capabilities are safely ignored in L07. This policy
keeps the installed L05/L06 API foundation stable; it does not add a new global
error code or silently upgrade previously installed application files. Search
uses portable bound `LIKE` expressions, whose case sensitivity follows the
configured database collation.

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
6. Install the API foundation and emit thin smart controllers (V2-L05/L06).
7. Generate the allowlisted query specification pipeline (V2-L07).
8. Add later API modules only in their explicitly assigned lots.

This sequence avoids a big-bang rewrite and keeps each change reviewable.
