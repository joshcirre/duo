# Contributing to Duo

Thank you for considering contributing to Duo!

## Development Setup

### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- npm or pnpm

### Installation

1. Clone the repository:
```bash
git clone https://github.com/joshcirre/duo.git
cd duo
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install JavaScript dependencies:
```bash
npm install
```

## Development Workflow

### PHP Development

Run tests:
```bash
composer test
```

Run specific test suites:
```bash
composer test:lint      # Code style
composer test:types     # Static analysis
composer test:unit      # PHPUnit tests
```

Format code:
```bash
composer lint
```

### JavaScript/TypeScript Development

Build the Vite plugin:
```bash
npm run build
```

Watch for changes:
```bash
npm run dev
```

Run tests:
```bash
npm test
```

Type check:
```bash
npm run typecheck
```

Lint:
```bash
npm run lint
```

## Project Structure

```
duo/
├── config/                 # Laravel config file
│   └── duo.php
├── resources/
│   ├── js/duo/            # TypeScript client library
│   │   ├── core/          # Core database logic
│   │   ├── sync/          # Sync queue
│   │   ├── livewire/      # Livewire integration
│   │   └── index.ts       # Main entry point
│   └── views/components/  # Blade components (sync-status)
├── src/                   # PHP source code
│   ├── Commands/          # Artisan commands
│   ├── DuoServiceProvider.php  # Main service provider
│   ├── ModelRegistry.php  # Model tracking
│   ├── Syncable.php       # Model trait
│   └── WithDuo.php        # Livewire component trait
├── vite-plugin/           # Vite plugin source
│   └── src/
│       └── index.ts
├── tests/                 # PHP tests
└── workbench/            # Development/testing Laravel app
```

## Key Concepts

### PHP Side

- **Syncable Trait** (`JoshCirre\Duo\Syncable`): Marks models for IndexedDB caching
- **WithDuo Trait** (`JoshCirre\Duo\WithDuo`): Marks Livewire components for automatic transformation to Alpine.js
- **ModelRegistry**: Tracks all models using Duo
- **DuoServiceProvider**: Registers the package with Laravel and transforms HTML
- **Commands**: Artisan commands for discovery and manifest generation

### JavaScript Side

- **DuoDatabase**: Dexie.js wrapper for IndexedDB
- **SyncQueue**: Manages write-behind synchronization
- **LivewireIntegration**: Hooks into Livewire lifecycle
- **Vite Plugin**: Build-time integration

## Code Style

### PHP

We follow Laravel conventions and use:
- **Pint** for code formatting
- **PHPStan** (level 9) for static analysis
- Strict types in all files

### TypeScript

- **ESLint** for linting
- **Prettier** for formatting
- Strict TypeScript configuration

## Testing

### PHP Tests

Located in `tests/`. We use Pest for testing.

Example:
```php
test('model can be registered', function () {
    $registry = new ModelRegistry();
    $registry->register(Post::class);

    expect($registry->has(Post::class))->toBeTrue();
});
```

### JavaScript Tests

Located in `vite-plugin/` and `resources/js/`. We use Vitest.

Example:
```typescript
import { describe, it, expect } from 'vitest';
import { DuoDatabase } from './core/database';

describe('DuoDatabase', () => {
  it('initializes with config', () => {
    const db = new DuoDatabase({
      databaseName: 'test',
      databaseVersion: 1,
      stores: {},
    });

    expect(db).toBeDefined();
  });
});
```

## Pull Request Process

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make your changes
4. Run tests: `composer test && npm test`
5. Commit your changes: `git commit -m "Add my feature"`
6. Push to your fork: `git push origin feature/my-feature`
7. Open a Pull Request

### PR Guidelines

- Keep PRs focused on a single feature or fix
- Update documentation for new features
- Add tests for new functionality
- Ensure all tests pass
- Follow existing code style

## Release Process

1. Update version in `package.json` and `composer.json`
2. Update CHANGELOG.md
3. Create a git tag: `git tag v1.0.0`
4. Push tag: `git push --tags`
5. Publish to npm: `npm publish`
6. Publish to Packagist (automatic via webhook)

## Questions?

Feel free to open an issue for:
- Bug reports
- Feature requests
- Questions about implementation
- Documentation improvements

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
