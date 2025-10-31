<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Registry for models that use Duo for IndexedDB syncing.
 *
 * @internal
 */
final class ModelRegistry
{
    /**
     * @var array<string, array{table: string, primaryKey: string, fillable: array<string>, timestamps: bool, schema: array<string, array{type: string, nullable: bool, default: mixed}>}>
     */
    private array $models = [];

    /**
     * Register a model class.
     */
    public function register(string $modelClass): void
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return;
        }

        /** @var Model $instance */
        $instance = new $modelClass;

        $fillable = $this->determineFillableAttributes($instance);
        $schema = $this->extractDatabaseSchema($instance, $fillable);

        $this->models[$modelClass] = [
            'table' => $instance->getTable(),
            'primaryKey' => $instance->getKeyName(),
            'fillable' => $fillable,
            'timestamps' => $instance->timestamps,
            'schema' => $schema,
        ];
    }

    /**
     * Determine fillable attributes from either $fillable or $guarded.
     *
     * @return array<string>
     */
    private function determineFillableAttributes(Model $instance): array
    {
        // First, try to get fillable directly
        $fillable = $instance->getFillable();

        // If fillable is explicitly defined and not empty, use it
        if (! empty($fillable)) {
            return $fillable;
        }

        // Otherwise, check if guarded is being used
        $guarded = $instance->getGuarded();

        // If guarded is ['*'], nothing is fillable
        if (in_array('*', $guarded, true)) {
            return [];
        }

        // Get all columns from the table
        try {
            $tableName = $instance->getTable();
            $columns = Schema::getColumnListing($tableName);

            // Remove guarded columns
            $fillable = array_diff($columns, $guarded);

            // Remove timestamps if they exist (they're handled separately)
            if ($instance->timestamps) {
                $fillable = array_diff($fillable, [$instance->getCreatedAtColumn(), $instance->getUpdatedAtColumn()]);
            }

            // Remove primary key
            $fillable = array_diff($fillable, [$instance->getKeyName()]);

            return array_values($fillable);
        } catch (\Exception $e) {
            \Log::warning("[Duo] Failed to determine fillable attributes for {$instance->getTable()}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Extract database schema information for fillable columns.
     *
     * @param  array<string>  $fillable
     * @return array<string, array{type: string, nullable: bool, default: mixed}>
     */
    private function extractDatabaseSchema(Model $instance, array $fillable): array
    {
        $schema = [];

        try {
            $tableName = $instance->getTable();
            $columns = Schema::getColumns($tableName);

            // Create a map of column name => column info
            $columnMap = [];
            foreach ($columns as $column) {
                $columnMap[$column['name']] = $column;
            }

            // Add primary key to schema
            $primaryKey = $instance->getKeyName();
            if (isset($columnMap[$primaryKey])) {
                $columnInfo = $columnMap[$primaryKey];
                $schema[$primaryKey] = [
                    'type' => $this->mapDatabaseTypeToJs($columnInfo['type_name']),
                    'nullable' => $columnInfo['nullable'],
                    'default' => $columnInfo['default'],
                    'autoIncrement' => $columnInfo['auto_increment'],
                ];
            }

            // Add fillable columns to schema
            foreach ($fillable as $column) {
                if (isset($columnMap[$column])) {
                    $columnInfo = $columnMap[$column];
                    $schema[$column] = [
                        'type' => $this->mapDatabaseTypeToJs($columnInfo['type_name']),
                        'nullable' => $columnInfo['nullable'],
                        'default' => $columnInfo['default'],
                    ];
                }
            }

            // Add timestamp columns if enabled
            if ($instance->timestamps) {
                foreach ([$instance->getCreatedAtColumn(), $instance->getUpdatedAtColumn()] as $timestampColumn) {
                    if (isset($columnMap[$timestampColumn])) {
                        $columnInfo = $columnMap[$timestampColumn];
                        $schema[$timestampColumn] = [
                            'type' => 'date',
                            'nullable' => $columnInfo['nullable'],
                            'default' => $columnInfo['default'],
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning("[Duo] Failed to extract database schema for {$instance->getTable()}: {$e->getMessage()}");
        }

        return $schema;
    }

    /**
     * Map database types to JavaScript-friendly types for IndexedDB.
     */
    private function mapDatabaseTypeToJs(string $databaseType): string
    {
        return match (strtolower($databaseType)) {
            'bigint', 'integer', 'smallint', 'tinyint', 'mediumint' => 'number',
            'decimal', 'float', 'double', 'real' => 'number',
            'boolean', 'bool' => 'boolean',
            'date', 'datetime', 'timestamp', 'time' => 'date',
            'json', 'jsonb' => 'object',
            'text', 'string', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext' => 'string',
            'blob', 'binary', 'varbinary' => 'blob',
            default => 'string',
        };
    }

    /**
     * Get all registered models.
     *
     * @return array<string, array{table: string, primaryKey: string, fillable: array<string>, timestamps: bool, schema: array<string, array{type: string, nullable: bool, default: mixed}>}>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * Get a specific model's metadata.
     *
     * @return array{table: string, primaryKey: string, fillable: array<string>, timestamps: bool, schema: array<string, array{type: string, nullable: bool, default: mixed}>}|null
     */
    public function get(string $modelClass): ?array
    {
        return $this->models[$modelClass] ?? null;
    }

    /**
     * Check if a model is registered.
     */
    public function has(string $modelClass): bool
    {
        return isset($this->models[$modelClass]);
    }

    /**
     * Generate a manifest for the JavaScript side.
     *
     * @return array<string, mixed>
     */
    public function toManifest(): array
    {
        $manifest = [
            '_version' => now()->timestamp, // Timestamp-based version like Laravel migrations
            'stores' => [],
        ];

        foreach ($this->models as $class => $metadata) {
            $storeName = str_replace('\\', '_', $class);

            // Build indexes array - include primary key and timestamp columns for sorting
            $indexes = [$metadata['primaryKey']];
            if ($metadata['timestamps']) {
                $indexes[] = 'created_at';
                $indexes[] = 'updated_at';
            }

            $manifest['stores'][$storeName] = [
                'model' => $class,
                'table' => $metadata['table'],
                'primaryKey' => $metadata['primaryKey'],
                'indexes' => $indexes,
                'timestamps' => $metadata['timestamps'],
                'schema' => $metadata['schema'], // Include schema with column types
                // API endpoints for background sync
                'endpoints' => [
                    'index' => "/api/duo/{$metadata['table']}",
                    'show' => "/api/duo/{$metadata['table']}/{id}",
                    'store' => "/api/duo/{$metadata['table']}",
                    'update' => "/api/duo/{$metadata['table']}/{id}",
                    'destroy' => "/api/duo/{$metadata['table']}/{id}",
                ],
                // Sync configuration
                'sync' => [
                    'enabled' => true,
                    'batchSize' => 50, // Sync in batches
                    'retryAttempts' => 3,
                    'retryDelay' => 1000, // ms
                ],
            ];
        }

        return $manifest;
    }
}
