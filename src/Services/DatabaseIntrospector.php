<?php

namespace Votapil\VotaCrudGenerator\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseIntrospector
{
    /**
     * Get all columns with full metadata for a table.
     *
     * Uses Schema::getColumns() (Laravel 11+) which returns:
     * name, type_name, type, nullable, default, auto_increment, comment
     *
     * @return array<int, array<string, mixed>>
     */
    public function getColumns(string $table): array
    {
        return Schema::getColumns($table);
    }

    /**
     * Get fillable column names (excluding system columns).
     *
     * @return array<int, string>
     */
    public function getFillableColumns(string $table): array
    {
        $exclude = ['id', 'created_at', 'updated_at', 'deleted_at'];

        return collect($this->getColumns($table))
            ->pluck('name')
            ->reject(fn (string $name) => in_array($name, $exclude))
            ->values()
            ->all();
    }

    /**
     * Get all columns except auto-increment primary key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNonPrimaryColumns(string $table): array
    {
        return collect($this->getColumns($table))
            ->reject(fn (array $col) => $col['auto_increment'] ?? false)
            ->values()
            ->all();
    }

    /**
     * Get foreign key information for a table.
     *
     * Returns array of: name, columns, foreign_schema, foreign_table, foreign_columns, on_update, on_delete
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForeignKeys(string $table): array
    {
        return Schema::getForeignKeys($table);
    }

    /**
     * Get indexes for a table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIndexes(string $table): array
    {
        return Schema::getIndexes($table);
    }

    /**
     * Check if a table has a deleted_at column (SoftDeletes).
     */
    public function hasSoftDeletes(string $table): bool
    {
        return collect($this->getColumns($table))
            ->contains(fn (array $col) => $col['name'] === 'deleted_at');
    }

    /**
     * Check if a table has created_at and updated_at columns.
     */
    public function hasTimestamps(string $table): bool
    {
        $names = collect($this->getColumns($table))->pluck('name');

        return $names->contains('created_at') && $names->contains('updated_at');
    }

    /**
     * Check if a table exists in the database.
     */
    public function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * Build Eloquent relationship definitions from foreign keys.
     *
     * @return array<int, array{type: string, method: string, related_model: string, foreign_key: string, owner_key: string}>
     */
    public function buildRelationships(string $table): array
    {
        $relationships = [];

        // BelongsTo: this table has FK pointing to another table
        foreach ($this->getForeignKeys($table) as $fk) {
            $foreignTable = $fk['foreign_table'];
            $localColumn = $fk['columns'][0] ?? null;
            $foreignColumn = $fk['foreign_columns'][0] ?? 'id';

            if (! $localColumn) {
                continue;
            }

            // Derive model name from table: "users" -> "User"
            $relatedModel = Str::studly(Str::singular($foreignTable));
            // Derive method name: "user_id" -> "user"
            $methodName = Str::camel(Str::replaceLast('_id', '', $localColumn));

            $relationships[] = [
                'type' => 'belongsTo',
                'method' => $methodName,
                'related_model' => $relatedModel,
                'foreign_key' => $localColumn,
                'owner_key' => $foreignColumn,
            ];
        }

        return $relationships;
    }

    /**
     * Build reverse relationships: find all tables that have FK pointing to this table.
     *
     * @return array<int, array{type: string, method: string, related_model: string, foreign_key: string, local_key: string}>
     */
    public function buildReverseRelationships(string $table): array
    {
        $relationships = [];
        $allTables = Schema::getTables();

        foreach ($allTables as $tableInfo) {
            $otherTable = $tableInfo['name'];
            if ($otherTable === $table) {
                continue;
            }

            foreach ($this->getForeignKeys($otherTable) as $fk) {
                if ($fk['foreign_table'] === $table) {
                    $localColumn = $fk['columns'][0] ?? null;
                    $foreignColumn = $fk['foreign_columns'][0] ?? 'id';

                    if (! $localColumn) {
                        continue;
                    }

                    $relatedModel = Str::studly(Str::singular($otherTable));
                    $methodName = Str::camel(Str::plural(Str::singular($otherTable)));

                    $relationships[] = [
                        'type' => 'hasMany',
                        'method' => $methodName,
                        'related_model' => $relatedModel,
                        'foreign_key' => $localColumn,
                        'local_key' => $foreignColumn,
                    ];
                }
            }
        }

        return $relationships;
    }

    /**
     * Map a DB column type to a Laravel validation rule.
     */
    public function getColumnValidationRule(array $column): string
    {
        $rules = [];

        if (($column['nullable'] ?? false) === true) {
            $rules[] = 'nullable';
        } else {
            $rules[] = 'required';
        }

        $typeName = strtolower($column['type_name'] ?? $column['type'] ?? 'string');
        $name = strtolower($column['name'] ?? '');

        $isBoolean = str_contains($typeName, 'bool')
            || str_contains($typeName, 'tinyint(1)')
            || str_starts_with($name, 'is_')
            || str_starts_with($name, 'has_')
            || str_starts_with($name, 'can_');

        $rules[] = match (true) {
            $isBoolean => 'boolean',
            str_contains($typeName, 'int') => 'integer',
            str_contains($typeName, 'decimal'),
            str_contains($typeName, 'float'),
            str_contains($typeName, 'double'),
            str_contains($typeName, 'numeric') => 'numeric',
            str_contains($typeName, 'bool') => 'boolean',
            str_contains($typeName, 'date'),
            str_contains($typeName, 'timestamp') => 'date',
            str_contains($typeName, 'json'),
            str_contains($typeName, 'jsonb') => 'json',
            str_contains($typeName, 'text'),
            str_contains($typeName, 'longtext'),
            str_contains($typeName, 'mediumtext') => 'string',
            str_contains($typeName, 'varchar'),
            str_contains($typeName, 'char'),
            str_contains($typeName, 'character varying') => 'string|max:255',
            str_contains($typeName, 'uuid') => 'uuid',
            str_contains($typeName, 'enum') => 'string',
            default => 'string',
        };

        return implode('|', $rules);
    }

    /**
     * Map a DB column type to an Eloquent $casts entry.
     *
     * Returns null if no cast is needed (strings don't need cast).
     */
    public function getColumnCast(array $column): ?string
    {
        $typeName = strtolower($column['type_name'] ?? $column['type'] ?? 'string');
        $name = strtolower($column['name'] ?? '');

        $isBoolean = str_contains($typeName, 'bool')
            || str_contains($typeName, 'tinyint(1)')
            || str_starts_with($name, 'is_')
            || str_starts_with($name, 'has_')
            || str_starts_with($name, 'can_');

        return match (true) {
            $isBoolean => 'boolean',
            str_contains($typeName, 'json'),
            str_contains($typeName, 'jsonb') => 'array',
            str_contains($typeName, 'decimal'),
            str_contains($typeName, 'numeric') => 'decimal:2',
            str_contains($typeName, 'float'),
            str_contains($typeName, 'double') => 'float',
            str_contains($typeName, 'int')
                && ! str_contains($column['name'], '_id') => 'integer',
            str_contains($typeName, 'date')
                && ! str_contains($typeName, 'datetime')
                && ! str_contains($typeName, 'timestamp') => 'date',
            str_contains($typeName, 'datetime'),
            str_contains($typeName, 'timestamp') => 'datetime',
            default => null,
        };
    }

    /**
     * Generate a Faker method call for a Factory based on column metadata.
     */
    public function getColumnFaker(array $column): string
    {
        $name = strtolower($column['name']);
        $typeName = strtolower($column['type_name'] ?? $column['type'] ?? 'string');

        // Name-based guesses first (higher priority)
        return match (true) {
            str_contains($name, 'email') => 'fake()->safeEmail()',
            str_contains($name, 'phone') => 'fake()->phoneNumber()',
            str_contains($name, 'name')
                && str_contains($name, 'first') => 'fake()->firstName()',
            str_contains($name, 'name')
                && str_contains($name, 'last') => 'fake()->lastName()',
            $name === 'name'
                || str_contains($name, 'name') => 'fake()->name()',
            str_contains($name, 'address') => 'fake()->address()',
            str_contains($name, 'city') => 'fake()->city()',
            str_contains($name, 'country') => 'fake()->country()',
            str_contains($name, 'zip')
                || str_contains($name, 'postal') => 'fake()->postcode()',
            str_contains($name, 'url')
                || str_contains($name, 'website') => 'fake()->url()',
            str_contains($name, 'title') => 'fake()->sentence(3)',
            str_contains($name, 'description')
                || str_contains($name, 'body')
                || str_contains($name, 'content') => 'fake()->paragraph()',
            str_contains($name, 'slug') => 'fake()->slug()',
            str_contains($name, 'uuid') => 'fake()->uuid()',
            str_contains($name, 'password') => "bcrypt('password')",
            str_contains($name, 'token') => 'Str::random(64)',
            str_contains($name, 'ip') => 'fake()->ipv4()',
            str_contains($name, 'color') => 'fake()->hexColor()',
            str_contains($name, 'lat') => 'fake()->latitude()',
            str_contains($name, 'lng')
                || str_contains($name, 'lon') => 'fake()->longitude()',
            str_ends_with($name, '_id') => '1',
            // Type-based fallbacks
            str_contains($typeName, 'bool') => 'fake()->boolean()',
            str_contains($typeName, 'int') => 'fake()->randomNumber()',
            str_contains($typeName, 'decimal'),
            str_contains($typeName, 'float'),
            str_contains($typeName, 'double'),
            str_contains($typeName, 'numeric') => 'fake()->randomFloat(2, 0, 1000)',
            str_contains($typeName, 'date')
                && ! str_contains($typeName, 'datetime') => 'fake()->date()',
            str_contains($typeName, 'datetime'),
            str_contains($typeName, 'timestamp') => 'fake()->dateTime()',
            str_contains($typeName, 'json'),
            str_contains($typeName, 'jsonb') => '[]',
            str_contains($typeName, 'text') => 'fake()->paragraph()',
            str_contains($typeName, 'uuid') => 'fake()->uuid()',
            default => 'fake()->word()',
        };
    }

    /**
     * Get unique columns (from unique indexes).
     *
     * @return array<int, string>
     */
    public function getUniqueColumns(string $table): array
    {
        return collect($this->getIndexes($table))
            ->filter(fn (array $idx) => ($idx['unique'] ?? false) && ! ($idx['primary'] ?? false))
            ->flatMap(fn (array $idx) => $idx['columns'] ?? [])
            ->unique()
            ->values()
            ->all();
    }
}
