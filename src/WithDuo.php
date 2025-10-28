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
 */
trait WithDuo
{
    //
}
