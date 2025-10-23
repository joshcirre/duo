<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Commands;

use Illuminate\Console\Command;
use JoshCirre\Duo\ModelRegistry;

final class DiscoverModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duo:discover';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover and register models that use Duo';

    /**
     * Execute the console command.
     */
    public function handle(ModelRegistry $registry): int
    {
        $this->info('Discovering models...');

        $models = $registry->all();

        if (empty($models)) {
            $this->warn('No models found using Duo.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($models).' model(s):');

        foreach ($models as $class => $metadata) {
            $this->line("  - {$class} (table: {$metadata['table']})");
        }

        return self::SUCCESS;
    }
}
