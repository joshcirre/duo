import { initializeDuo } from '../../resources/js/duo/index';

// Initialize Duo when the app loads
document.addEventListener('DOMContentLoaded', async () => {
    try {
        await initializeDuo({
            debug: true,
            syncInterval: 5000,
            maxRetries: 3,
        });
        console.log('Duo initialized successfully');
    } catch (error) {
        console.error('Failed to initialize Duo:', error);
    }
});
