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
    }

    /**
     * Register this model with the Duo model registry.
     */
    protected static function registerDuoModel(): void
    {
        if (! app()->bound(\JoshCirre\Duo\ModelRegistry::class)) {
            return;
        }

        /** @var \JoshCirre\Duo\ModelRegistry $registry */
        $registry = app(\JoshCirre\Duo\ModelRegistry::class);
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

    /**
     * Determine if this model should be cached in IndexedDB.
     */
    public function shouldSyncToDuo(): bool
    {
        return true;
    }

    /**
     * Get the Duo sync strategy for this model.
     */
    public function getDuoSyncStrategy(): string
    {
        return config('duo.sync_strategy', 'write-behind');
    }
}
