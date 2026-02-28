<?php

namespace Votapil\VotaCrudGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Votapil\VotaCrudGenerator\Services\DatabaseIntrospector;
use Votapil\VotaCrudGenerator\Services\StubRenderer;

class CrudGenerateCommand extends Command
{
    protected $signature = 'vota:crud
        {name : Model name in singular, e.g. User, BlogPost}
        {--path= : Sub-path inside namespace, e.g. Admin or Blog/V2}
        {--namespace= : Override base model namespace, e.g. App\\Models\\Admin}
        {--table= : Explicit table name (overrides convention)}
        {--no-policy : Skip Policy generation}
        {--no-request : Skip FormRequest generation}
        {--no-resource : Skip Resource generation}
        {--no-factory : Skip Factory generation}
        {--no-route : Skip route injection}
        {--force : Overwrite existing files}
        {--yes : Skip confirmation prompt}';

    protected $description = 'Generate CRUD files (Model, Controller, Requests, Resource, Policy, Factory) from database table schema';

    public function __construct(
        protected DatabaseIntrospector $introspector,
        protected StubRenderer $renderer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        $path = $this->option('path') ?: '';
        $force = (bool) $this->option('force');

        // Resolve table name
        $tableName = $this->option('table') ?: Str::snake(Str::plural($name));

        // Check table exists
        if (!$this->introspector->tableExists($tableName)) {
            $this->error("Table '{$tableName}' does not exist in the database.");
            $this->line('Available tables:');
            foreach (\Illuminate\Support\Facades\Schema::getTables() as $t) {
                $this->line("  - {$t['name']}");
            }

            return self::FAILURE;
        }

        $this->info("🔍 Analysing table: {$tableName}");

        // Gather all metadata
        $meta = $this->gatherMetadata($name, $tableName, $path);

        $this->displayMetaSummary($meta);

        if (!$this->option('yes') && !$this->confirm('Proceed with generation?', true)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        // Generate files
        $config = config('votacrudgenerator.generate', []);

        if ($config['model'] ?? true) {
            $this->generateModel($meta, $force);
        }

        if (($config['controller'] ?? true)) {
            $this->generateController($meta, $force);
        }

        if (($config['store_request'] ?? true) && !$this->option('no-request')) {
            $this->generateRequest($meta, 'Store', $force);
        }

        if (($config['update_request'] ?? true) && !$this->option('no-request')) {
            $this->generateRequest($meta, 'Update', $force);
        }

        if (($config['resource'] ?? true) && !$this->option('no-resource')) {
            $this->generateResource($meta, $force);
        }

        if (($config['policy'] ?? true) && !$this->option('no-policy')) {
            $this->generatePolicy($meta, $force);
        }

        if (($config['factory'] ?? true) && !$this->option('no-factory')) {
            $this->generateFactory($meta, $force);
        }

        if (($config['routes'] ?? true) && !$this->option('no-route')) {
            $this->injectRoute($meta);
        }

        $this->newLine();
        $this->info('✅ CRUD generation complete for ' . $name . '!');

        return self::SUCCESS;
    }

    /**
     * Gather all metadata from the database and options.
     */
    protected function gatherMetadata(string $name, string $tableName, string $path): array
    {
        $config = config('votacrudgenerator');
        $detect = $config['detect'] ?? [];

        $columns = $this->introspector->getColumns($tableName);
        $fillableNames = $this->introspector->getFillableColumns($tableName);
        $uniqueColumns = $this->introspector->getUniqueColumns($tableName);

        $hasSoftDeletes = ($detect['soft_deletes'] ?? true)
            ? $this->introspector->hasSoftDeletes($tableName)
            : false;

        $hasTimestamps = ($detect['timestamps'] ?? true)
            ? $this->introspector->hasTimestamps($tableName)
            : false;

        // Relationships
        $belongsTo = [];
        $hasMany = [];

        if ($detect['relationships'] ?? true) {
            $belongsTo = $this->introspector->buildRelationships($tableName);
            $hasMany = $this->introspector->buildReverseRelationships($tableName);
        }

        // Casts
        $casts = [];
        if ($detect['casts'] ?? true) {
            foreach ($columns as $col) {
                if (in_array($col['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                    continue;
                }
                $cast = $this->introspector->getColumnCast($col);
                if ($cast !== null) {
                    $casts[$col['name']] = $cast;
                }
            }
        }

        // Validation rules
        $storeRules = [];
        $updateRules = [];
        foreach ($columns as $col) {
            if (in_array($col['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $rule = $this->introspector->getColumnValidationRule($col);

            // Add unique rule if applicable
            if (in_array($col['name'], $uniqueColumns)) {
                $rule .= "|unique:{$tableName},{$col['name']}";
            }

            $storeRules[$col['name']] = $rule;
            // Update rules: prefix with 'sometimes'
            $updateRules[$col['name']] = 'sometimes|' . $rule;
        }

        // Faker fields for factory
        $fakerFields = [];
        foreach ($columns as $col) {
            if (in_array($col['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            $fakerFields[$col['name']] = $this->introspector->getColumnFaker($col);
        }

        // Namespace resolution
        $baseModelNs = $this->option('namespace')
            ?: ($config['namespaces']['model'] ?? 'App\\Models');
        $subPath = $path ? '\\' . str_replace('/', '\\', $path) : '';

        $modelNamespace = $baseModelNs . $subPath;
        $modelFullClass = $modelNamespace . '\\' . $name;

        $baseControllerNs = $config['namespaces']['controller'] ?? 'App\\Http\\Controllers';
        $controllerNamespace = $baseControllerNs . $subPath;

        $baseRequestNs = $config['namespaces']['request'] ?? 'App\\Http\\Requests';
        $requestNamespace = $baseRequestNs . $subPath;

        $baseResourceNs = $config['namespaces']['resource'] ?? 'App\\Http\\Resources';
        $resourceNamespace = $baseResourceNs . $subPath;

        $basePolicyNs = $config['namespaces']['policy'] ?? 'App\\Policies';
        $policyNamespace = $basePolicyNs;

        $modelVariable = Str::camel($name);
        $modelPluralLower = Str::lower(Str::plural($name));

        return [
            'modelName' => $name,
            'tableName' => $tableName,
            'path' => $path,

            // Namespaces
            'modelNamespace' => $modelNamespace,
            'modelFullClass' => $modelFullClass,
            'controllerNamespace' => $controllerNamespace,
            'requestNamespace' => $requestNamespace,
            'resourceNamespace' => $resourceNamespace,
            'policyNamespace' => $policyNamespace,

            // Resource & Request full classes (for controller imports)
            'resourceFullClass' => $resourceNamespace . '\\' . $name . 'Resource',
            'storeRequestFullClass' => $requestNamespace . '\\' . $name . 'StoreRequest',
            'updateRequestFullClass' => $requestNamespace . '\\' . $name . 'UpdateRequest',

            // Variables
            'modelVariable' => $modelVariable,
            'modelPluralLower' => $modelPluralLower,

            // Schema data
            'columns' => $columns,
            'fillableNames' => $fillableNames,
            'uniqueColumns' => $uniqueColumns,

            // Detections
            'softDeletes' => $hasSoftDeletes,
            'hasTimestamps' => $hasTimestamps,
            'belongsTo' => $belongsTo,
            'hasMany' => $hasMany,
            'hasRelationships' => count($belongsTo) > 0 || count($hasMany) > 0,
            'casts' => $casts,

            // Rules
            'storeRules' => $storeRules,
            'updateRules' => $updateRules,

            // Factory
            'fakerFields' => $fakerFields,
        ];
    }

    /**
     * Display a summary of what will be generated.
     */
    protected function displayMetaSummary(array $meta): void
    {
        $this->newLine();
        $this->line("<fg=cyan>📋 Model:</> {$meta['modelFullClass']}");
        $this->line("<fg=cyan>📦 Table:</> {$meta['tableName']}");
        $this->line('<fg=cyan>📝 Fillable fields:</> ' . implode(', ', $meta['fillableNames']));

        if ($meta['softDeletes']) {
            $this->line('<fg=yellow>♻️  SoftDeletes:</> detected (deleted_at column found)');
        }

        if (count($meta['belongsTo']) > 0) {
            $this->line('<fg=green>🔗 BelongsTo:</>');
            foreach ($meta['belongsTo'] as $rel) {
                $this->line("   → {$rel['method']}() → {$rel['related_model']}");
            }
        }

        if (count($meta['hasMany']) > 0) {
            $this->line('<fg=green>🔗 HasMany:</>');
            foreach ($meta['hasMany'] as $rel) {
                $this->line("   → {$rel['method']}() → {$rel['related_model']}");
            }
        }

        if (count($meta['casts']) > 0) {
            $this->line('<fg=magenta>🎯 Casts:</> ' . implode(', ', array_map(
                fn ($k, $v) => "{$k} → {$v}",
                array_keys($meta['casts']),
                $meta['casts']
            )));
        }

        $this->newLine();
    }

    // ──────────────────────────────────────────────────────────────
    // File generators
    // ──────────────────────────────────────────────────────────────

    protected function generateModel(array $meta, bool $force): void
    {
        $fillableStr = $this->formatArrayMultiline($meta['fillableNames'], 8);
        $castsStr = count($meta['casts']) > 0
            ? $this->formatAssocArrayMultiline($meta['casts'], 12)
            : '';

        $relationshipsStr = $this->buildRelationshipsCode($meta);

        // Generate PHPDoc properties
        $phpDoc = "/**\n";
        foreach ($meta['columns'] as $col) {
            $type = $this->mapDbTypeToPhpType($col);
            if ($col['nullable'] ?? false) {
                $type .= '|null';
            }
            $comment = !empty($col['comment']) ? ' ' . $col['comment'] : '';
            $phpDoc .= " * @property {$type} \${$col['name']}{$comment}\n";
        }
        $phpDoc .= ' */';

        $content = $this->renderer->render('Model', [
            'modelNamespace' => $meta['modelNamespace'],
            'modelName' => $meta['modelName'],
            'phpDoc' => $phpDoc,
            'fillable' => $fillableStr,
            'casts' => $castsStr,
            'softDeletes' => $meta['softDeletes'],
            'hasRelationships' => $meta['hasRelationships'],
            'relationships' => $relationshipsStr,
        ]);

        $filePath = $this->namespacePath($meta['modelNamespace'], $meta['modelName']);
        $this->writeFile($filePath, $content, $force, 'Model');
    }

    protected function generateController(array $meta, bool $force): void
    {
        $sqb = config('votacrudgenerator.packages.spatie_query_builder', false);

        $allowedFiltersStr = '';
        $allowedSortsStr = '';
        $allowedIncludesStr = '';

        if ($sqb) {
            $columnNames = array_column($meta['columns'], 'name');
            $allowedFiltersStr = ltrim($this->formatArrayMultiline($columnNames, 16));
            $allowedSortsStr = ltrim($this->formatArrayMultiline($columnNames, 16));

            $includes = array_merge(
                array_column($meta['belongsTo'], 'method'),
                array_column($meta['hasMany'], 'method')
            );
            $allowedIncludesStr = count($includes) > 0
                ? ltrim($this->formatArrayMultiline($includes, 16))
                : '[]';
        }

        $content = $this->renderer->render('Controller', [
            'controllerNamespace' => $meta['controllerNamespace'],
            'modelName' => $meta['modelName'],
            'modelVariable' => $meta['modelVariable'],
            'modelPluralLower' => $meta['modelPluralLower'],
            'modelFullClass' => $meta['modelFullClass'],
            'resourceFullClass' => $meta['resourceFullClass'],
            'storeRequestFullClass' => $meta['storeRequestFullClass'],
            'updateRequestFullClass' => $meta['updateRequestFullClass'],
            'softDeletes' => $meta['softDeletes'],
            'hasRelationships' => $meta['hasRelationships'],
            'spatieQueryBuilder' => $sqb,
            'allowedFilters' => $allowedFiltersStr,
            'allowedSorts' => $allowedSortsStr,
            'allowedIncludes' => $allowedIncludesStr,
        ]);

        $filePath = $this->namespacePath($meta['controllerNamespace'], $meta['modelName'] . 'Controller');
        $this->writeFile($filePath, $content, $force, 'Controller');
    }

    protected function generateRequest(array $meta, string $type, bool $force): void
    {
        $stubName = $type === 'Store' ? 'StoreRequest' : 'UpdateRequest';
        $rules = $type === 'Store' ? $meta['storeRules'] : $meta['updateRules'];
        $className = $meta['modelName'] . $type . 'Request';

        $rulesStr = $this->formatAssocArrayMultiline(
            array_map(fn ($rule) => "'{$rule}'", $rules),
            12,
            false
        );

        $content = $this->renderer->render($stubName, [
            'requestNamespace' => $meta['requestNamespace'],
            'modelName' => $meta['modelName'],
            'validationRules' => $rulesStr,
        ]);

        $filePath = $this->namespacePath($meta['requestNamespace'], $className);
        $this->writeFile($filePath, $content, $force, "{$type}Request");
    }

    protected function generateResource(array $meta, bool $force): void
    {
        $lines = [];
        foreach ($meta['columns'] as $col) {
            $line = "'{$col['name']}' => \$this->{$col['name']},";
            if (!empty($col['comment'])) {
                $line .= " // {$col['comment']}";
            }
            $lines[] = '            ' . $line;
        }

        $fieldsStr = "[\n" . implode("\n", $lines) . "\n        ]";

        $content = $this->renderer->render('Resource', [
            'resourceNamespace' => $meta['resourceNamespace'],
            'modelName' => $meta['modelName'],
            'resourceFields' => $fieldsStr,
        ]);

        $filePath = $this->namespacePath($meta['resourceNamespace'], $meta['modelName'] . 'Resource');
        $this->writeFile($filePath, $content, $force, 'Resource');
    }

    protected function generatePolicy(array $meta, bool $force): void
    {
        // Avoid duplicate parameter name when model is User (User $user, User $user)
        $policyModelVar = $meta['modelVariable'] === 'user'
            ? 'model'
            : $meta['modelVariable'];

        $content = $this->renderer->render('Policy', [
            'policyNamespace' => $meta['policyNamespace'],
            'modelName' => $meta['modelName'],
            'modelFullClass' => $meta['modelFullClass'],
            'modelVariable' => $policyModelVar,
            'softDeletes' => $meta['softDeletes'],
        ]);

        $filePath = $this->namespacePath($meta['policyNamespace'], $meta['modelName'] . 'Policy');
        $this->writeFile($filePath, $content, $force, 'Policy');
    }

    protected function generateFactory(array $meta, bool $force): void
    {
        $fakerStr = $this->formatAssocArrayMultiline(
            $meta['fakerFields'],
            12,
            false
        );

        $content = $this->renderer->render('Factory', [
            'modelFullClass' => $meta['modelFullClass'],
            'modelName' => $meta['modelName'],
            'factoryFields' => $fakerStr,
        ]);

        $filePath = database_path('factories/' . $meta['modelName'] . 'Factory.php');
        $this->writeFile($filePath, $content, $force, 'Factory');
    }

    protected function injectRoute(array $meta): void
    {
        $routeFile = base_path(config('votacrudgenerator.route_file', 'routes/api.php'));
        $prefix = config('votacrudgenerator.route_prefix', '');

        $routeName = $prefix
            ? $prefix . '/' . $meta['modelPluralLower']
            : $meta['modelPluralLower'];

        $controllerClass = $meta['controllerNamespace'] . '\\' . $meta['modelName'] . 'Controller';

        $routeLine = "\nRoute::apiResource('{$routeName}', \\{$controllerClass}::class);";

        if ($meta['softDeletes']) {
            $routeLine .= "\nRoute::patch('{$routeName}/{id}/restore', [\\{$controllerClass}::class, 'restore'])->name('{$meta['modelPluralLower']}.restore');";
            $routeLine .= "\nRoute::delete('{$routeName}/{id}/force', [\\{$controllerClass}::class, 'forceDestroy'])->name('{$meta['modelPluralLower']}.forceDestroy');";
        }

        if (!File::exists($routeFile)) {
            $this->warn("Route file not found: {$routeFile}. Printing route snippet instead:");
            $this->line($routeLine);

            return;
        }

        // Check if route already exists
        $existingContent = File::get($routeFile);
        if (str_contains($existingContent, "'{$routeName}'")) {
            $this->warn("⚠️  Route for '{$routeName}' already exists in {$routeFile}. Skipping.");

            return;
        }

        File::append($routeFile, "\n" . $routeLine . "\n");
        $this->info("📌 Route added to {$routeFile}");
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Build relationship method code from metadata.
     */
    protected function buildRelationshipsCode(array $meta): string
    {
        $code = '';
        $modelNs = $meta['modelNamespace'];

        foreach ($meta['belongsTo'] as $rel) {
            $code .= <<<PHP

    public function {$rel['method']}(): BelongsTo
    {
        return \$this->belongsTo({$rel['related_model']}::class, '{$rel['foreign_key']}', '{$rel['owner_key']}');
    }

PHP;
        }

        foreach ($meta['hasMany'] as $rel) {
            $code .= <<<PHP

    public function {$rel['method']}(): HasMany
    {
        return \$this->hasMany({$rel['related_model']}::class, '{$rel['foreign_key']}', '{$rel['local_key']}');
    }

PHP;
        }

        return rtrim($code);
    }

    /**
     * Map a DB column type to a PHP type for PHPDoc.
     */
    protected function mapDbTypeToPhpType(array $column): string
    {
        $typeName = strtolower($column['type_name'] ?? $column['type'] ?? 'string');
        $name = strtolower($column['name'] ?? '');

        $isBoolean = str_contains($typeName, 'bool')
            || str_contains($typeName, 'tinyint(1)');

        return match (true) {
            $isBoolean => 'bool',
            str_contains($typeName, 'int') => 'int',
            str_contains($typeName, 'decimal'),
            str_contains($typeName, 'float'),
            str_contains($typeName, 'double'),
            str_contains($typeName, 'numeric') => 'float',
            str_contains($typeName, 'bool') => 'bool',
            str_contains($typeName, 'json') => 'array',
            str_contains($typeName, 'date'),
            str_contains($typeName, 'timestamp') => '\Illuminate\Support\Carbon',
            default => 'string',
        };
    }

    /**
     * Convert a namespace to a file path and return the full path for a class.
     */
    protected function namespacePath(string $namespace, string $className): string
    {
        // App\Models\Admin -> app/Models/Admin
        $relative = str_replace('\\', '/', $namespace);

        // Remove leading App/ and convert to app/
        if (str_starts_with($relative, 'App/')) {
            $relative = 'app/' . substr($relative, 4);
        } elseif (str_starts_with($relative, 'Database/')) {
            $relative = 'database/' . substr($relative, 9);
        }

        return base_path("{$relative}/{$className}.php");
    }

    /**
     * Write content to a file, creating directories as needed.
     */
    protected function writeFile(string $path, string $content, bool $force, string $label): void
    {
        if (File::exists($path) && !$force) {
            $this->warn("⏭️  {$label} already exists: {$path} (use --force to overwrite)");

            return;
        }

        $dir = dirname($path);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $content);
        $this->info("✅ {$label} created: {$path}");
    }

    /**
     * Format an indexed array as a multiline PHP array string.
     */
    protected function formatArrayMultiline(array $items, int $indent): string
    {
        if (empty($items)) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent);
        $lines = array_map(fn ($item) => "{$pad}'{$item}',", $items);

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat(' ', $indent - 4) . ']';
    }

    /**
     * Format an associative array as a multiline PHP array string.
     */
    protected function formatAssocArrayMultiline(array $items, int $indent, bool $quoteValues = true): string
    {
        if (empty($items)) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($items as $key => $value) {
            $val = $quoteValues ? "'{$value}'" : $value;
            $lines[] = "{$pad}'{$key}' => {$val},";
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat(' ', $indent - 4) . ']';
    }
}
