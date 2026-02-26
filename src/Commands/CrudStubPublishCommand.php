<?php

namespace Votapil\VotaCrudGenerator\Commands;

use Illuminate\Console\Command;
use Votapil\VotaCrudGenerator\Services\StubRenderer;

class CrudStubPublishCommand extends Command
{
    protected $signature = 'vota:stubs
        {--force : Overwrite existing published stubs}';

    protected $description = 'Publish VotaCrudGenerator stub files for customization';

    public function __construct(
        protected StubRenderer $renderer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('📄 Publishing VotaCrudGenerator stubs...');
        $this->newLine();

        $force = (bool) $this->option('force');
        $destination = base_path('stubs/vendor/votacrud');

        // Check if already published
        if (is_dir($destination) && ! $force) {
            if (! $this->confirm('Stubs directory already exists. Overwrite?', false)) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        // Ask about optional packages
        $this->configurePackages();

        // Publish
        $published = $this->renderer->publishStubs();

        foreach ($published as $file) {
            $this->line("  ✅ Published: {$file}");
        }

        $this->newLine();
        $count = count($published);
        $this->info("🎉 {$count} stub(s) published to: {$destination}");
        $this->line('You can now customize these stubs to match your project style.');

        return self::SUCCESS;
    }

    /**
     * Ask about optional package integration and update config cache.
     */
    protected function configurePackages(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>📦 Optional package integration:</>');

        $useSpatieQueryBuilder = $this->confirm(
            'Use spatie/laravel-query-builder for filtering & sorting in controllers?',
            config('votacrudgenerator.packages.spatie_query_builder', false)
        );

        if ($useSpatieQueryBuilder) {
            $this->info('  → spatie/laravel-query-builder will be integrated.');
            $this->warn('  → Make sure to install it: composer require spatie/laravel-query-builder');
        }

        // Write preference to config
        config(['votacrudgenerator.packages.spatie_query_builder' => $useSpatieQueryBuilder]);

        $this->newLine();
    }
}
