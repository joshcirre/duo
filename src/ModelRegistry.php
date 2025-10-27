<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Database\Eloquent\Model;

/**
 * Registry for models that use Duo for IndexedDB syncing.
 *
 * @internal
 */
final class ModelRegistry
{
    /**
     * @var array<string, array{table: string, primaryKey: string, fillable: array<string>, timestamps: bool}>
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

        $this->models[$modelClass] = [
            'table' => $instance->getTable(),
            'primaryKey' => $instance->getKeyName(),
            'fillable' => $instance->getFillable(),
            'timestamps' => $instance->timestamps,
        ];
    }

    /**
     * Get all registered models.
     *
     * @return array<string, array{table: string, primaryKey: string, fillable: array<string>, timestamps: bool}>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * Get a specific model's metadata.
     *
     * @return array{table: string, primaryKey: string, fillable: array<string>, timestamps: bool}|null
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
        $manifest = [];

        foreach ($this->models as $class => $metadata) {
            $storeName = str_replace('\\', '_', $class);

            $manifest[$storeName] = [
                'model' => $class,
                'table' => $metadata['table'],
                'primaryKey' => $metadata['primaryKey'],
                'indexes' => [$metadata['primaryKey']],
                'timestamps' => $metadata['timestamps'],
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
