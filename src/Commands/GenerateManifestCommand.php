<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Commands;

use Illuminate\Console\Command;
use JoshCirre\Duo\ModelRegistry;

final class GenerateManifestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duo:generate {--path=resources/js/duo : Output path for the manifest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the Duo manifest file for IndexedDB schema';

    /**
     * Execute the console command.
     */
    public function handle(ModelRegistry $registry): int
    {
        $path = $this->option('path');
        $outputPath = base_path($path.'/manifest.json');

        $manifest = $registry->toManifest();

        if (empty($manifest)) {
            $this->warn('No models found. Manifest will be empty.');
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $outputPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->info("Manifest generated at: {$outputPath}");
        $this->info('Models registered: '.count($manifest['stores'] ?? []));

        return self::SUCCESS;
    }
}
