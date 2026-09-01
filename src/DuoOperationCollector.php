<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

use Illuminate\Database\Eloquent\Model;

/**
 * Captures Eloquent operations on Syncable models during Livewire requests.
 *
 * Registered as a singleton — Syncable model events record operations here,
 * and DuoSynth drains them during dehydration to include in the effect payload.
 * The client receives exactly what database operations occurred.
 *
 * @internal
 */
final class DuoOperationCollector
{
    /** @var array<int, array{type: string, store: string, table: string, key: mixed, data: array<string, mixed>}> */
    private array $operations = [];

    /**
     * Record an Eloquent operation on a Syncable model.
     */
    public function record(string $type, Model $model): void
    {
        if (! in_array(Syncable::class, class_uses_recursive($model))) {
            return;
        }

        $this->operations[] = [
            'type' => $type,
            'store' => str_replace('\\', '_', get_class($model)),
            'table' => $model->getTable(),
            'key' => $model->getKey(),
            'data' => $model->toArray(),
        ];
    }

    /**
     * Drain all collected operations and reset.
     *
     * @return array<int, array{type: string, store: string, table: string, key: mixed, data: array<string, mixed>}>
     */
    public function drain(): array
    {
        $ops = $this->operations;
        $this->operations = [];

        return $ops;
    }

    /**
     * Check if any operations have been recorded.
     */
    public function hasOperations(): bool
    {
        return ! empty($this->operations);
    }
}
