<?php

namespace Rhino\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ExportTypesCommand extends Command
{
    protected $signature = 'rhino:export-types
                            {--output= : Explicit output file path (overrides env paths)}';

    protected $description = 'Generate TypeScript interfaces from registered Rhino models via OpenAPI intermediate format';

    public function handle(): int
    {
        $models = config('rhino.models', []);

        if (empty($models)) {
            $this->warn('No models registered in rhino.models config.');
            return self::SUCCESS;
        }

        $outputPaths = $this->resolveOutputPaths();

        if (empty($outputPaths)) {
            $this->error('No output paths configured. Set RHINO_CLIENT_PATH and/or RHINO_MOBILE_PATH in .env, or use --output flag.');
            return self::FAILURE;
        }

        $schemas = [];

        foreach ($models as $slug => $modelClass) {
            if (! class_exists($modelClass)) {
                $this->warn("Model class does not exist: {$modelClass}");
                continue;
            }

            $interfaceName = $this->slugToInterfaceName($slug);
            $properties = $this->introspectColumns($modelClass);

            if (empty($properties)) {
                $this->warn("No columns found for model: {$slug} ({$modelClass})");
                continue;
            }

            $schemas[$interfaceName] = [
                'type' => 'object',
                'properties' => $properties,
            ];
        }

        if (empty($schemas)) {
            $this->warn('No schemas generated.');
            return self::SUCCESS;
        }

        $openApiSpec = $this->buildOpenApiSpec($schemas);

        $tempFile = tempnam(sys_get_temp_dir(), 'rhino_openapi_') . '.json';
        file_put_contents($tempFile, json_encode($openApiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            foreach ($outputPaths as $outputPath) {
                $dir = dirname($outputPath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                $result = $this->runOpenApiTypescript($tempFile, $outputPath);

                if ($result !== 0) {
                    $this->error("Failed to generate types at {$outputPath}. Is openapi-typescript installed? Run: npm install -g openapi-typescript");
                    return self::FAILURE;
                }

                $this->info("Generated TypeScript types at: {$outputPath}");
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return self::SUCCESS;
    }

    private function resolveOutputPaths(): array
    {
        $explicit = $this->option('output');
        if ($explicit) {
            return [$explicit];
        }

        $paths = [];

        $clientPath = config('rhino.client_path', env('RHINO_CLIENT_PATH'));
        if ($clientPath) {
            $paths[] = rtrim($clientPath, '/') . '/src/types/rhino.d.ts';
        }

        $mobilePath = config('rhino.mobile_path', env('RHINO_MOBILE_PATH'));
        if ($mobilePath) {
            $paths[] = rtrim($mobilePath, '/') . '/src/types/rhino.d.ts';
        }

        return $paths;
    }

    private function slugToInterfaceName(string $slug): string
    {
        return Str::studly(Str::singular($slug));
    }

    private function introspectColumns(string $modelClass): array
    {
        $instance = new $modelClass;
        $table = $instance->getTable();

        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumns($table);
        $properties = [];

        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $column['type_name'] ?? $column['type'] ?? 'string';
            $nullable = $column['nullable'] ?? false;

            $openApiType = $this->mapColumnType($type);

            $prop = $openApiType;
            if ($nullable) {
                $prop['nullable'] = true;
            }

            $properties[$name] = $prop;
        }

        return $properties;
    }

    private function mapColumnType(string $dbType): array
    {
        $dbType = strtolower($dbType);

        return match (true) {
            in_array($dbType, ['integer', 'int', 'bigint', 'smallint', 'tinyint', 'mediumint']) => ['type' => 'integer'],
            in_array($dbType, ['decimal', 'float', 'double', 'real', 'numeric']) => ['type' => 'number'],
            in_array($dbType, ['boolean', 'bool']) => ['type' => 'boolean'],
            in_array($dbType, ['timestamp', 'datetime', 'timestamptz', 'date', 'time']) => ['type' => 'string', 'format' => 'date-time'],
            in_array($dbType, ['json', 'jsonb']) => ['type' => 'object'],
            default => ['type' => 'string'],
        };
    }

    private function buildOpenApiSpec(array $schemas): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'API') . ' Models',
                'version' => '1.0.0',
            ],
            'paths' => new \stdClass(),
            'components' => [
                'schemas' => $schemas,
            ],
        ];
    }

    private function runOpenApiTypescript(string $inputFile, string $outputFile): int
    {
        $command = sprintf(
            'npx openapi-typescript %s -o %s 2>&1',
            escapeshellarg($inputFile),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error(implode("\n", $output));
        }

        return $exitCode;
    }
}
