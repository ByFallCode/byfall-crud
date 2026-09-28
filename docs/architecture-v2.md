# Byfall CRUD V2 architecture

## Current architecture

Byfall CRUD V1 exposes three Artisan commands. `MakeEntity` currently owns schema
inspection, metadata inference, code rendering and file writes. `MakeApiCollection`
contains a second, partially duplicated schema inspection implementation. The V2
migration is incremental: existing commands and generated Laravel code remain the
public contract while internal responsibilities are extracted behind typed data.

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

`TypeMapper` centralizes Blueprint-method-to-SQL and SQL-to-Eloquent-cast mappings
that were duplicated by `MakeEntity` and `MakeApiCollection`. V2-L01 deliberately
preserves the historical mapping results. Type improvements belong to later lots.

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
4. Move rendering into focused generators and stubs.
5. Add richer inference and optional API modules only after the core is stable.

This sequence avoids a big-bang rewrite and keeps each change reviewable.
