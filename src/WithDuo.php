<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

/**
 * Trait for Livewire components to enable Duo offline-first functionality.
 *
 * Simply add this trait to your Livewire component to enable local-first,
 * offline-capable behavior with zero configuration.
 *
 * Example:
 * ```php
 * use JoshCirre\Duo\WithDuo;
 * use Livewire\Component;
 *
 * class TodoList extends Component
 * {
 *     use WithDuo;
 *
 *     public function render()
 *     {
 *         return view('livewire.todo-list', [
 *             'todos' => Todo::latest()->get(),
 *         ]);
 *     }
 * }
 * ```
 *
 * Advanced usage with type-safe configuration:
 * ```php
 * use JoshCirre\Duo\{WithDuo, DuoConfig};
 *
 * class TodoList extends Component
 * {
 *     use WithDuo;
 *
 *     protected function duoConfig(): DuoConfig
 *     {
 *         return DuoConfig::make(
 *             syncInterval: 3000,
 *             timestampRefreshInterval: 5000,
 *             debug: true
 *         );
 *     }
 * }
 * ```
 */
trait WithDuo
{
    /**
     * Get Duo configuration for this component.
     *
     * Override this method to customize Duo behavior for this component.
     * Component config takes precedence over global config (config/duo.php).
     *
     * Use DuoConfig::make() for full IDE autocomplete and type safety.
     */
    protected function duoConfig(): DuoConfig
    {
        return DuoConfig::make();
    }
}
