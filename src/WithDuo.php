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
 * Advanced usage with custom configuration:
 * ```php
 * class TodoList extends Component
 * {
 *     use WithDuo;
 *
 *     protected function duoConfig(): array
 *     {
 *         return [
 *             'timestampRefreshInterval' => 5000, // Refresh every 5 seconds
 *             'debug' => true, // Enable debug logging
 *         ];
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
     * Available options:
     * - syncInterval (int): Milliseconds between sync attempts (default: from config/duo.php or 5000)
     * - timestampRefreshInterval (int): Milliseconds between timestamp updates (default: from config/duo.php or 10000)
     * - maxRetryAttempts (int): Maximum retry attempts for failed syncs (default: from config/duo.php or 3)
     * - debug (bool): Enable verbose console logging (default: from config/duo.php or false)
     *
     * @return array<string, mixed>
     */
    protected function duoConfig(): array
    {
        return [
            // All options are optional - defaults come from config/duo.php
            // Uncomment and customize as needed:
            // 'syncInterval' => 5000,
            // 'timestampRefreshInterval' => 10000,
            // 'maxRetryAttempts' => 3,
            // 'debug' => false,
        ];
    }
}
