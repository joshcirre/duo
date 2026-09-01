<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Database\Eloquent\Model;

/**
 * Trait to enable Duo IndexedDB syncing on Eloquent models.
 */
trait Syncable
{
    /**
     * Boot the Syncable trait.
     */
    public static function bootSyncable(): void
    {
        // Register this model with Duo when it's first used
        static::registerDuoModel();

        // Register Eloquent event listeners for operation capture.
        // When the DuoOperationCollector is active (during a Livewire method call),
        // these events record what actually happened at the database level.
        static::created(function (Model $model) {
            if (app()->bound(DuoOperationCollector::class)) {
                app(DuoOperationCollector::class)->record('create', $model);
            }
        });

        static::updated(function (Model $model) {
            if (app()->bound(DuoOperationCollector::class)) {
                app(DuoOperationCollector::class)->record('update', $model);
            }
        });

        static::deleted(function (Model $model) {
            if (app()->bound(DuoOperationCollector::class)) {
                app(DuoOperationCollector::class)->record('delete', $model);
            }
        });
    }

    /**
     * Register this model with the Duo model registry.
     */
    protected static function registerDuoModel(): void
    {
        if (! app()->bound(ModelRegistry::class)) {
            return;
        }

        /** @var ModelRegistry $registry */
        $registry = app(ModelRegistry::class);
        $registry->register(static::class);
    }

    /**
     * Get the IndexedDB store name for this model.
     */
    public function getDuoStoreName(): string
    {
        return str_replace('\\', '_', static::class);
    }

    /**
     * Convert the model to an array suitable for IndexedDB storage.
     *
     * @return array<string, mixed>
     */
    public function toDuoArray(): array
    {
        $array = $this->toArray();

        // Add metadata for sync tracking
        $array['_duo_synced_at'] = now()->timestamp;
        $array['_duo_version'] = $this->{$this->getUpdatedAtColumn()}?->timestamp ?? time();

        return $array;
    }
}
