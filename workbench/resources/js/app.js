import { initializeDuo } from '../../../resources/js/duo/index';
import manifest from '../../../resources/js/duo/manifest.json';

// Runs at module load (before DOMContentLoaded), so Duo's Livewire hooks are
// attached before Livewire.start() initializes the components on the page.
initializeDuo({ manifest, debug: true })
    .then(() => console.log('Duo initialized successfully'))
    .catch((error) => console.error('Failed to initialize Duo:', error));
