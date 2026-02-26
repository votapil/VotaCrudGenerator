<?php

namespace Votapil\VotaCrudGenerator\Services;

use Illuminate\Support\Facades\File;

class StubRenderer
{
    /**
     * Render a stub file by replacing placeholders.
     *
     * Supports:
     * - Simple placeholders: {{ variableName }}
     * - Conditional blocks: {{#if variableName}} ... {{/if variableName}}
     * - Negative conditionals: {{#unless variableName}} ... {{/unless variableName}}
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $stubName, array $variables = []): string
    {
        $content = $this->getStubContent($stubName);

        // Process conditional blocks first
        $content = $this->processConditionals($content, $variables);

        // Then replace simple placeholders
        $content = $this->replacePlaceholders($content, $variables);

        return $content;
    }

    /**
     * Get the stub content, checking published stubs first, then package defaults.
     */
    protected function getStubContent(string $stubName): string
    {
        // Check for user-published stubs first
        $publishedPath = base_path("stubs/vendor/votacrud/{$stubName}.stub");

        if (File::exists($publishedPath)) {
            return File::get($publishedPath);
        }

        // Fall back to package stubs
        $packagePath = $this->getPackageStubPath($stubName);

        if (! File::exists($packagePath)) {
            throw new \RuntimeException("Stub file not found: {$stubName}.stub");
        }

        return File::get($packagePath);
    }

    /**
     * Get the path to the package's stub directory.
     */
    public function getPackageStubPath(string $stubName = ''): string
    {
        $basePath = dirname(__DIR__, 2).'/stubs';

        return $stubName ? "{$basePath}/{$stubName}.stub" : $basePath;
    }

    /**
     * Get all available stub names.
     *
     * @return array<int, string>
     */
    public function getAvailableStubs(): array
    {
        $path = $this->getPackageStubPath();

        if (! File::isDirectory($path)) {
            return [];
        }

        return collect(File::files($path))
            ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Publish stubs to the application's stubs directory.
     *
     * @return array<int, string> List of published files
     */
    public function publishStubs(): array
    {
        $source = $this->getPackageStubPath();
        $destination = base_path('stubs/vendor/votacrud');

        if (! File::isDirectory($destination)) {
            File::makeDirectory($destination, 0755, true);
        }

        $published = [];

        foreach (File::files($source) as $file) {
            $target = $destination.'/'.$file->getFilename();
            File::copy($file->getPathname(), $target);
            $published[] = $target;
        }

        return $published;
    }

    /**
     * Process {{#if var}}...{{/if var}} and {{#unless var}}...{{/unless var}} blocks.
     *
     * @param  array<string, mixed>  $variables
     */
    protected function processConditionals(string $content, array $variables): string
    {
        // Process #if blocks
        $content = preg_replace_callback(
            '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\s+\1\}\}/s',
            function ($matches) use ($variables) {
                $varName = $matches[1];
                $block = $matches[2];

                return ! empty($variables[$varName]) ? $block : '';
            },
            $content
        );

        // Process #unless blocks
        $content = preg_replace_callback(
            '/\{\{#unless\s+(\w+)\}\}(.*?)\{\{\/unless\s+\1\}\}/s',
            function ($matches) use ($variables) {
                $varName = $matches[1];
                $block = $matches[2];

                return empty($variables[$varName]) ? $block : '';
            },
            $content
        );

        return $content;
    }

    /**
     * Replace {{ variableName }} placeholders with values.
     *
     * @param  array<string, mixed>  $variables
     */
    protected function replacePlaceholders(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $content = str_replace("{{ {$key} }}", (string) $value, $content);
            }
        }

        return $content;
    }
}
