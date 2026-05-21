<?php

namespace Rhino\Blueprint;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class BlueprintParser
{
    /**
     * Parse a roles YAML file into a normalized array.
     *
     * @param string $filePath Absolute path to _roles.yaml
     * @return array<string, array{name: string, description: string}>
     * @throws \RuntimeException If file is not readable or has invalid structure
     */
    public function parseRoles(string $filePath): array
    {
        $data = $this->loadYaml($filePath);

        if (!isset($data['roles']) || !is_array($data['roles'])) {
            throw new \RuntimeException("Invalid roles file: missing 'roles' key in {$filePath}");
        }

        $roles = [];
        foreach ($data['roles'] as $slug => $definition) {
            if (!is_string($slug)) {
                throw new \RuntimeException("Role slug must be a string in {$filePath}");
            }

            $roles[$slug] = [
                'name' => $definition['name'] ?? Str::title($slug),
                'description' => $definition['description'] ?? '',
            ];
        }

        return $roles;
    }

    /**
     * Parse a model blueprint YAML file into a normalized array.
     *
     * @param string $filePath Absolute path to model YAML file
     * @return array Normalized blueprint structure
     * @throws \RuntimeException If file is not readable or has invalid structure
     */
    public function parseModel(string $filePath): array
    {
        $data = $this->loadYaml($filePath);

        if (!isset($data['model'])) {
            throw new \RuntimeException("Invalid blueprint file: missing 'model' key in {$filePath}");
        }

        $modelName = $data['model'];
        $slug = $data['slug'] ?? Str::snake(Str::plural($modelName));
        $tableName = $data['table'] ?? $slug;

        return [
            'model' => $modelName,
            'slug' => $slug,
            'table' => $tableName,
            'options' => $this->normalizeOptions($data['options'] ?? []),
            'columns' => $this->normalizeColumns($data['columns'] ?? []),
            'relationships' => $data['relationships'] ?? [],
            'permissions' => $this->normalizePermissions($data['permissions'] ?? []),
            'source_file' => basename($filePath),
        ];
    }

    /**
     * Load and parse a YAML file.
     */
    protected function loadYaml(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Blueprint file not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("Blueprint file not readable: {$filePath}");
        }

        $content = file_get_contents($filePath);

        if ($content === false || trim($content) === '') {
            throw new \RuntimeException("Blueprint file is empty: {$filePath}");
        }

        try {
            $data = Yaml::parse($content);
        } catch (\Exception $e) {
            throw new \RuntimeException("Invalid YAML in {$filePath}: {$e->getMessage()}");
        }

        if (!is_array($data)) {
            throw new \RuntimeException("YAML file must contain an associative array: {$filePath}");
        }

        return $data;
    }

    /**
     * Normalize options with defaults.
     */
    protected function normalizeOptions(array $options): array
    {
        return [
            'belongs_to_organization' => $options['belongs_to_organization'] ?? false,
            'soft_deletes' => $options['soft_deletes'] ?? true,
            'audit_trail' => $options['audit_trail'] ?? false,
            'owner' => $options['owner'] ?? null,
            'except_actions' => $options['except_actions'] ?? [],
            'pagination' => $options['pagination'] ?? false,
            'per_page' => $options['per_page'] ?? 25,
        ];
    }

    /**
     * Normalize column definitions from YAML format to internal format.
     */
    protected function normalizeColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $name => $definition) {
            if (is_string($definition)) {
                // Short syntax: "title: string"
                $definition = ['type' => $definition];
            }

            $normalized[] = [
                'name' => $name,
                'type' => $definition['type'] ?? 'string',
                'nullable' => $definition['nullable'] ?? false,
                'unique' => $definition['unique'] ?? false,
                'index' => $definition['index'] ?? false,
                'default' => $definition['default'] ?? null,
                'filterable' => $definition['filterable'] ?? false,
                'sortable' => $definition['sortable'] ?? false,
                'searchable' => $definition['searchable'] ?? false,
                'precision' => $definition['precision'] ?? null,
                'scale' => $definition['scale'] ?? null,
                'foreignModel' => $definition['foreign_model'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Normalize permission definitions.
     * Handles both "*" wildcard string and array field lists.
     */
    protected function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $role => $definition) {
            $normalized[$role] = [
                'actions' => $definition['actions'] ?? [],
                'show_fields' => $this->normalizeFieldList($definition['show_fields'] ?? '*'),
                'create_fields' => $this->normalizeFieldList($definition['create_fields'] ?? []),
                'update_fields' => $this->normalizeFieldList($definition['update_fields'] ?? []),
                'hidden_fields' => $definition['hidden_fields'] ?? [],
            ];
        }

        return $normalized;
    }

    /**
     * Normalize a field list: "*" becomes ['*'], arrays stay as arrays.
     */
    protected function normalizeFieldList(mixed $fields): array
    {
        if ($fields === '*') {
            return ['*'];
        }

        if (is_string($fields)) {
            return [$fields];
        }

        if (is_array($fields)) {
            return $fields;
        }

        return [];
    }

    /**
     * Compute SHA-256 hash of a file's contents for manifest tracking.
     */
    public function computeFileHash(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Cannot read file for hashing: {$filePath}");
        }

        return hash('sha256', $content);
    }
}
