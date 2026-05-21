<?php

namespace Rhino\Commands\Traits;

use Illuminate\Support\Str;

/**
 * Shared helper methods for code generation commands.
 *
 * Used by both GenerateCommand (interactive) and BlueprintCommand (YAML-driven).
 */
trait GeneratorHelpers
{
    protected function replacePlaceholders(string $stub, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $stub = str_replace("{{ {$key} }}", $value, $stub);
        }

        return $stub;
    }

    protected function arrayToPhpString(array $items, int $indent = 8): string
    {
        if (empty($items)) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent);
        $inner = implode(",\n", array_map(fn($item) => "{$pad}    '{$item}'", $items));

        return "[\n{$inner},\n{$pad}]";
    }

    protected function assocArrayToPhpString(array $items, int $indent = 8): string
    {
        if (empty($items)) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($items as $key => $value) {
            $lines[] = "{$pad}    '{$key}' => '{$value}'";
        }

        $inner = implode(",\n", $lines);

        return "[\n{$inner},\n{$pad}]";
    }

    protected function columnToValidationRule(array $column, string $tableName): string
    {
        $rules = [];

        if ($column['nullable']) {
            $rules[] = 'nullable';
        } else {
            $rules[] = 'required';
        }

        if (!empty($column['unique'])) {
            $rules[] = "unique:{$tableName},{$column['name']}";
        }

        switch ($column['type']) {
            case 'string':
                $rules[] = 'string';
                $rules[] = 'max:255';
                break;
            case 'text':
                $rules[] = 'string';
                break;
            case 'integer':
            case 'bigInteger':
                $rules[] = 'integer';
                break;
            case 'boolean':
                $rules[] = 'boolean';
                break;
            case 'date':
            case 'datetime':
            case 'timestamp':
                $rules[] = 'date';
                break;
            case 'decimal':
            case 'float':
                $rules[] = 'numeric';
                break;
            case 'json':
                $rules[] = 'array';
                break;
            case 'uuid':
                $rules[] = 'uuid';
                break;
            case 'foreignId':
                $rules[] = 'integer';
                if ($column['foreignModel']) {
                    $foreignTable = Str::snake(Str::plural($column['foreignModel']));
                    $rules[] = "exists:{$foreignTable},id";
                }
                break;
        }

        return implode('|', $rules);
    }

    protected function columnToMigrationLine(array $column): string
    {
        $name = $column['name'];
        $type = $column['type'];

        if ($type === 'foreignId') {
            $line = "\$table->foreignId('{$name}')";
            if ($column['nullable']) {
                $line .= '->nullable()';
            }
            if ($column['foreignModel']) {
                $foreignTable = Str::snake(Str::plural($column['foreignModel']));
                $line .= "->constrained('{$foreignTable}')->cascadeOnDelete()";
            } else {
                $line .= '->constrained()->cascadeOnDelete()';
            }
            if (!empty($column['unique'])) {
                $line .= '->unique()';
            }
            if ($column['index']) {
                $line .= '->index()';
            }
            return $line . ';';
        }

        if ($type === 'decimal') {
            $precision = $column['precision'] ?? 8;
            $scale = $column['scale'] ?? 2;
            $line = "\$table->decimal('{$name}', {$precision}, {$scale})";
        } else {
            $line = "\$table->{$type}('{$name}')";
        }

        if ($column['nullable']) {
            $line .= '->nullable()';
        }

        if (!empty($column['unique'])) {
            $line .= '->unique()';
        }

        if ($column['default'] !== null) {
            $defaultValue = $this->formatDefaultValue($column['default'], $type);
            $line .= "->default({$defaultValue})";
        }

        if ($column['index']) {
            $line .= '->index()';
        }

        return $line . ';';
    }

    protected function formatDefaultValue(mixed $value, string $type): string
    {
        if (in_array($type, ['integer', 'bigInteger', 'decimal', 'float'])) {
            return (string) $value;
        }

        if ($type === 'boolean') {
            return in_array(strtolower((string) $value), ['true', '1']) ? 'true' : 'false';
        }

        return "'" . addslashes((string) $value) . "'";
    }

    protected function columnToFakerValue(array $column): string
    {
        $type = $column['type'];
        $name = $column['name'];

        $nameBasedFaker = $this->nameBasedFakerValue($name);
        if ($nameBasedFaker !== null) {
            if ($column['nullable']) {
                return "fake()->optional()->{$nameBasedFaker}";
            }
            return "fake()->{$nameBasedFaker}";
        }

        if ($type === 'foreignId') {
            if ($column['foreignModel']) {
                return "\\App\\Models\\{$column['foreignModel']}::factory()";
            }
            return 'fake()->numberBetween(1, 10)';
        }

        $value = match ($type) {
            'string' => 'fake()->sentence(3)',
            'text' => 'fake()->paragraph()',
            'integer' => 'fake()->numberBetween(1, 100)',
            'bigInteger' => 'fake()->numberBetween(1, 10000)',
            'boolean' => 'fake()->boolean()',
            'date' => 'fake()->date()',
            'datetime', 'timestamp' => 'fake()->dateTime()',
            'decimal', 'float' => 'fake()->randomFloat(2, 0, 1000)',
            'json' => '[]',
            'uuid' => 'fake()->uuid()',
            default => 'fake()->word()',
        };

        if ($column['nullable'] && !in_array($type, ['json', 'boolean'])) {
            return str_replace('fake()->', 'fake()->optional()->', $value);
        }

        return $value;
    }

    protected function nameBasedFakerValue(string $name): ?string
    {
        return match (true) {
            $name === 'name' || $name === 'full_name' => 'name()',
            $name === 'first_name' => 'firstName()',
            $name === 'last_name' => 'lastName()',
            $name === 'email' => 'safeEmail()',
            $name === 'phone' || $name === 'phone_number' => 'phoneNumber()',
            $name === 'address' => 'address()',
            $name === 'city' => 'city()',
            $name === 'country' => 'country()',
            $name === 'zip_code' || $name === 'postal_code' => 'postcode()',
            $name === 'url' || $name === 'website' => 'url()',
            $name === 'title' => 'sentence(3)',
            $name === 'description' || $name === 'content' || $name === 'body' => 'paragraph()',
            $name === 'slug' => 'slug()',
            $name === 'price' || $name === 'amount' || $name === 'cost' => 'randomFloat(2, 1, 1000)',
            $name === 'quantity' || $name === 'count' => 'numberBetween(1, 100)',
            str_starts_with($name, 'is_') => 'boolean()',
            default => null,
        };
    }

    protected function buildRelationshipMethods(array $columns, array $relationships = [], bool $belongsToOrg = false): string
    {
        $methods = '';

        // BelongsTo from foreign keys in columns
        foreach ($columns as $col) {
            if ($col['type'] === 'foreignId' && $col['foreignModel']) {
                if ($belongsToOrg && $col['foreignModel'] === 'Organization') {
                    continue;
                }
                $relationName = Str::camel(Str::replaceLast('_id', '', $col['name']));
                $modelClass = $col['foreignModel'];
                $methods .= "    public function {$relationName}(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo\n";
                $methods .= "    {\n";
                $methods .= "        return \$this->belongsTo({$modelClass}::class);\n";
                $methods .= "    }\n\n";
            }
        }

        // Explicit relationships (from blueprint or interactive input)
        foreach ($relationships as $rel) {
            $type = $rel['type'];
            $model = $rel['model'];
            $methodName = $rel['method'] ?? null;

            if (!$methodName) {
                $methodName = match ($type) {
                    'hasMany' => Str::camel(Str::plural($model)),
                    'belongsToMany' => Str::camel(Str::plural($model)),
                    'hasOne' => Str::camel($model),
                    'belongsTo' => Str::camel($model),
                    default => Str::camel($model),
                };
            }

            // Skip if already generated from columns
            $alreadyGenerated = false;
            foreach ($columns as $col) {
                if ($col['type'] === 'foreignId' && $col['foreignModel'] === $model) {
                    $alreadyGenerated = true;
                    break;
                }
            }
            if ($alreadyGenerated && $type === 'belongsTo') {
                continue;
            }

            $returnType = match ($type) {
                'belongsTo' => 'BelongsTo',
                'hasMany' => 'HasMany',
                'hasOne' => 'HasOne',
                'belongsToMany' => 'BelongsToMany',
                default => 'HasMany',
            };

            $foreignKey = isset($rel['foreign_key']) ? ", '{$rel['foreign_key']}'" : '';

            $methods .= "    public function {$methodName}(): \\Illuminate\\Database\\Eloquent\\Relations\\{$returnType}\n";
            $methods .= "    {\n";
            $methods .= "        return \$this->{$type}({$model}::class{$foreignKey});\n";
            $methods .= "    }\n\n";
        }

        return $methods;
    }
}
