<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Livewire;

use Livewire\ComponentHook;

/**
 * Livewire component hook to enable Duo functionality.
 */
class DuoSynth extends ComponentHook
{
    public function mount()
    {
        // Check if component uses Duo trait
        if (method_exists($this->component, 'usesDuo') && $this->component->usesDuo()) {
            // Add Duo metadata to the component
            $this->component->js([
                'duo' => true,
                'duoModel' => $this->component->getDuoModelClass() ?? null,
            ]);
        }
    }

    public function render($view, $data)
    {
        if (method_exists($this->component, 'usesDuo') && $this->component->usesDuo()) {
            // Add wire:duo attribute to the root element
            $data['duoEnabled'] = true;
        }

        return $data;
    }

    public function dehydrate($context)
    {
        if (method_exists($this->component, 'usesDuo') && $this->component->usesDuo()) {
            // Add Duo metadata to component response
            $context->addEffect('duo', [
                'enabled' => true,
                'model' => $this->component->getDuoModelClass() ?? null,
            ]);
        }
    }
}
