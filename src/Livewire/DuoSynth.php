<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Livewire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JoshCirre\Duo\DuoServiceProvider;
use JoshCirre\Duo\Syncable;
use JoshCirre\Duo\WithDuo;
use Livewire\ComponentHook;

class DuoSynth extends ComponentHook
{
    public function dehydrate($context): void
    {
        if (! in_array(WithDuo::class, class_uses_recursive($this->component))) {
            return;
        }

        $provider = app(DuoServiceProvider::class);
        $meta = $provider->extractDuoMetadata($this->component);

        $state = [];
        foreach ($meta['models'] as $name => $info) {
            try {
                $value = $this->component->{$name} ?? null;
            } catch (\Throwable) {
                continue;
            }

            if ($value instanceof Collection) {
                $state[$name] = $value->map(function ($model) {
                    if ($model instanceof Model && in_array(Syncable::class, class_uses_recursive($model))) {
                        return $this->modelToDuoArray($model);
                    }
                    return $model instanceof Model ? $model->toArray() : $model;
                })->values()->all();
            } elseif ($value instanceof Model) {
                if (in_array(Syncable::class, class_uses_recursive($value))) {
                    $state[$name] = [$this->modelToDuoArray($value)];
                } else {
                    $state[$name] = [$value->toArray()];
                }
            } elseif (is_array($value)) {
                $state[$name] = $value;
            }
        }

        $context->addEffect('duo', [
            'enabled' => true,
            'meta' => $meta,
            'state' => $state,
        ]);
    }

    /**
     * @param  Model  $model  A model instance that uses the Syncable trait
     * @return array<string, mixed>
     */
    private function modelToDuoArray(Model $model): array
    {
        /** @phpstan-ignore-next-line */
        return $model->toDuoArray();
    }
}
