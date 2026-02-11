/**
 * Alpine integration for Duo components.
 *
 * In the new architecture, Alpine is used as a lightweight reactive layer
 * on top of the server-rendered HTML — not as a replacement for Blade.
 * The server renders everything normally; Alpine just provides local
 * reactivity when the component is in offline mode.
 */

export class DuoAlpineIntegration {
  initialize(): void {
    // Alpine integration is handled by the DuoLivewireInterceptor
    // which works with Livewire's own Alpine integration.
  }
}
