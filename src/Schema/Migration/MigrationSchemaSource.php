<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Schema\Migration;

use ByfallCode\ByfallCrud\Contracts\SchemaSource;
use ByfallCode\ByfallCrud\Metadata\ColumnMetadata;
use ByfallCode\ByfallCrud\Metadata\EntityMetadata;
use ByfallCode\ByfallCrud\Metadata\ForeignKeyMetadata;
use ByfallCode\ByfallCrud\Metadata\IndexMetadata;
use ByfallCode\ByfallCrud\Metadata\UniqueConstraintMetadata;
use ByfallCode\ByfallCrud\Schema\LegacyRuleBuilder;
use ByfallCode\ByfallCrud\Support\TypeMapper;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MigrationSchemaSource implements SchemaSource
{
    public function __construct(private readonly Filesystem $files) {}

    public function inspect(string $identifier, string $entityName = ''): EntityMetadata
    {
        if (!$this->files->exists($identifier)) {
            throw new \RuntimeException("Migration introuvable: {$identifier}");
        }
        $code = (string) $this->files->get($identifier);
        if (!preg_match('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $code, $tableMatch)) {
            if (str_contains($code, 'Schema::table(')) {
                throw new \RuntimeException('Construction de migration non supportée: Schema::table sans Schema::create.');
            }
            throw new \RuntimeException("Aucune création de table détectée dans la migration: {$identifier}");
        }
        foreach (['morphs(', 'nullableMorphs(', 'foreignUuid(', 'foreignUlid('] as $unsupported) {
            if (str_contains($code, '->'.$unsupported)) {
                throw new \RuntimeException("Construction de migration non supportée: {$unsupported}");
            }
        }

        $table = $tableMatch[1];
        $columns = [];
        $columnPositions = [];
        $foreignKeys = [];
        $uniqueConstraints = [];
        $indexes = [];
        $primaryKey = null;

        if (preg_match('/\$table->(?:id|bigIncrements|increments)\s*\(\s*(?:[\'\"]([^\'\"]+)[\'\"])?\s*\)/i', $code, $idMatch)) {
            $name = $idMatch[1] ?? 'id';
            $primaryKey = $name;
            $columns[] = new ColumnMetadata(
                name: $name,
                databaseType: 'bigint',
                normalizedType: 'integer',
                unsigned: true,
                autoIncrement: true,
                primary: true,
            );
            $columnPositions[$name] = 0;
            $indexes[] = new IndexMetadata([$name], primary: true, unique: true);
        }

        $pattern = '/\$table->([a-zA-Z_]+)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*([0-9]+))?\s*\)([^;]*);/m';
        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                [, $method, $name, $length, $chain] = $match + [null, null, null, null, null];
                $method = strtolower((string) $method);
                if (in_array($method, ['id', 'increments', 'bigincrements'], true) && $name === $primaryKey) continue;
                $databaseType = TypeMapper::methodToSqlType($method);
                $foreignKey = null;
                if ($method === 'foreignid') {
                    $referencedTable = $this->constrainedTable($chain) ?: Str::plural(Str::beforeLast($name, '_id'));
                    $foreignKey = new ForeignKeyMetadata($name, $referencedTable, 'id');
                    $foreignKeys[$name] = $foreignKey;
                }
                [$hasDefault, $defaultValue] = $this->defaultValue($chain);
                $unique = str_contains($chain, '->unique()');
                $column = new ColumnMetadata(
                    name: $name,
                    databaseType: $databaseType,
                    normalizedType: TypeMapper::sqlTypeToCast($databaseType),
                    nullable: str_contains($chain, '->nullable()'),
                    defaultValue: $defaultValue,
                    hasDefault: $hasDefault,
                    length: $length !== '' ? (int) $length : null,
                    unsigned: str_contains($chain, '->unsigned()') || $method === 'foreignid',
                    primary: str_contains($chain, '->primary()'),
                    unique: $unique,
                    foreignKey: $foreignKey,
                    generated: str_contains($chain, '->storedAs(') || str_contains($chain, '->virtualAs('),
                );
                $columnPositions[$name] = count($columns);
                $columns[] = $column;
                if ($column->primary) $primaryKey = $name;
                if ($unique) {
                    $uniqueConstraints[] = new UniqueConstraintMetadata([$name]);
                    $indexes[] = new IndexMetadata([$name], unique: true);
                }
            }
        }

        foreach ($this->arrayConstraints($code, 'unique') as [$constraintColumns, $constraintName]) {
            $uniqueConstraints[] = new UniqueConstraintMetadata($constraintColumns, $constraintName);
            $indexes[] = new IndexMetadata($constraintColumns, $constraintName, unique: true);
            if (count($constraintColumns) === 1 && isset($columnPositions[$constraintColumns[0]])) {
                $position = $columnPositions[$constraintColumns[0]];
                $columns[$position] = $this->withUnique($columns[$position]);
            }
        }
        foreach ($this->arrayConstraints($code, 'primary') as [$constraintColumns, $constraintName]) {
            $indexes[] = new IndexMetadata($constraintColumns, $constraintName, unique: true, primary: true);
            $primaryKey ??= $constraintColumns[0] ?? null;
        }

        if (str_contains($code, '->timestamps(') || str_contains($code, '->timestampsTz(')) {
            foreach (['created_at', 'updated_at'] as $name) {
                $columns[] = new ColumnMetadata($name, 'timestamp', 'datetime', nullable: true);
            }
        }
        $softDeletes = str_contains($code, '->softDeletes(') || str_contains($code, '->softDeletesTz(');
        if ($softDeletes) $columns[] = new ColumnMetadata('deleted_at', 'timestamp', 'datetime', nullable: true);

        $fillable = [];
        $casts = [];
        $storeRules = [];
        $updateRules = [];
        foreach ($columns as $column) {
            if (in_array($column->name, ['id', 'created_at', 'updated_at', 'deleted_at'], true) || $column->primary) continue;
            $fillable[] = $column->name;
            $casts[$column->name] = $column->normalizedType;
            [$storeRules[$column->name], $updateRules[$column->name]] = LegacyRuleBuilder::forColumn($table, $column);
        }

        return new EntityMetadata(
            name: $entityName !== '' ? $entityName : Str::studly(Str::singular($table)),
            table: $table,
            primaryKey: $primaryKey,
            columns: $columns,
            indexes: $indexes,
            uniqueConstraints: $uniqueConstraints,
            timestamps: str_contains($code, '->timestamps(') || str_contains($code, '->timestampsTz('),
            softDeletes: $softDeletes,
            fillable: array_values(array_unique($fillable)),
            casts: $casts,
            storeRules: $storeRules,
            updateRules: $updateRules,
        );
    }

    /** @return array<string, string> table => file */
    public function migrationFiles(string $directory): array
    {
        if (!$this->files->isDirectory($directory)) throw new \RuntimeException("Dossier migrations introuvable: {$directory}");
        $result = [];
        foreach ($this->files->allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') continue;
            $code = (string) $this->files->get($file->getRealPath());
            if (preg_match('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $code, $match)) {
                $result[$match[1]] = $file->getRealPath();
            }
        }
        ksort($result);
        return $result;
    }

    /** @return array{bool,mixed} */
    private function defaultValue(string $chain): array
    {
        if (!preg_match('/->default\(([^)]*)\)/', $chain, $match)) return [false, null];
        $raw = trim($match[1]);
        return [true, match (true) {
            $raw === 'null' => null,
            $raw === 'true' => true,
            $raw === 'false' => false,
            is_numeric($raw) => str_contains($raw, '.') ? (float) $raw : (int) $raw,
            preg_match('/^[\'\"](.*)[\'\"]$/', $raw, $value) === 1 => $value[1],
            default => $raw,
        }];
    }

    private function constrainedTable(string $chain): ?string
    {
        return preg_match('/->constrained\([\'\"]([^\'\"]+)[\'\"]\)/', $chain, $match) ? $match[1] : null;
    }

    /** @return list<array{list<string>,?string}> */
    private function arrayConstraints(string $code, string $method): array
    {
        $result = [];
        $pattern = '/\$table->'.$method.'\(\s*\[([^]]+)]\s*(?:,\s*[\'\"]([^\'\"]+)[\'\"])?\s*\)/';
        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $match[1], $columns);
                $result[] = [array_values($columns[1] ?? []), ($match[2] ?? '') !== '' ? $match[2] : null];
            }
        }
        return $result;
    }

    private function withUnique(ColumnMetadata $column): ColumnMetadata
    {
        return new ColumnMetadata(
            $column->name, $column->databaseType, $column->normalizedType, $column->nullable,
            $column->defaultValue, $column->hasDefault, $column->length, $column->precision,
            $column->scale, $column->unsigned, $column->autoIncrement, $column->primary,
            true, $column->foreignKey, $column->generated,
        );
    }
}
