<?php

namespace Rhino\Blueprint;

class BlueprintValidator
{
    /**
     * Valid column types for blueprint definitions.
     */
    protected const VALID_COLUMN_TYPES = [
        'string', 'text', 'integer', 'bigInteger', 'boolean',
        'date', 'datetime', 'timestamp', 'decimal', 'float',
        'json', 'uuid', 'foreignId',
    ];

    /**
     * Valid CRUD action names.
     */
    protected const VALID_ACTIONS = [
        'index', 'show', 'store', 'update', 'destroy',
        'trashed', 'restore', 'forceDelete',
    ];

    /**
     * Validate a parsed model blueprint.
     *
     * @param array $blueprint Parsed blueprint from BlueprintParser::parseModel()
     * @param array $validRoles Array of valid role slugs from _roles.yaml
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function validateModel(array $blueprint, array $validRoles = []): array
    {
        $errors = [];
        $warnings = [];

        // Model name is required
        if (empty($blueprint['model'])) {
            $errors[] = 'Model name is required.';
        } elseif (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $blueprint['model'])) {
            $errors[] = "Model name '{$blueprint['model']}' must be PascalCase (e.g., 'Contract', 'BlogPost').";
        }

        // Validate columns
        $columnErrors = $this->validateColumns($blueprint['columns'] ?? []);
        $errors = array_merge($errors, $columnErrors);

        // Validate permissions
        $permissionResult = $this->validatePermissions(
            $blueprint['permissions'] ?? [],
            $validRoles,
            $this->getColumnNames($blueprint['columns'] ?? [])
        );
        $errors = array_merge($errors, $permissionResult['errors']);
        $warnings = array_merge($warnings, $permissionResult['warnings']);

        // Validate options
        $optionErrors = $this->validateOptions($blueprint['options'] ?? []);
        $errors = array_merge($errors, $optionErrors);

        // Validate relationships
        $relErrors = $this->validateRelationships($blueprint['relationships'] ?? []);
        $errors = array_merge($errors, $relErrors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate a parsed roles definition.
     *
     * @param array $roles Parsed roles from BlueprintParser::parseRoles()
     * @return array{valid: bool, errors: string[]}
     */
    public function validateRoles(array $roles): array
    {
        $errors = [];

        if (empty($roles)) {
            $errors[] = 'At least one role must be defined.';
        }

        foreach ($roles as $slug => $definition) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
                $errors[] = "Role slug '{$slug}' must be lowercase with underscores only.";
            }

            if (empty($definition['name'])) {
                $errors[] = "Role '{$slug}' is missing a name.";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate column definitions.
     */
    protected function validateColumns(array $columns): array
    {
        $errors = [];
        $names = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? '';

            if (empty($name)) {
                $errors[] = 'Column name cannot be empty.';
                continue;
            }

            if (in_array($name, $names)) {
                $errors[] = "Duplicate column name: '{$name}'.";
            }
            $names[] = $name;

            if (!in_array($column['type'], self::VALID_COLUMN_TYPES)) {
                $errors[] = "Column '{$name}' has invalid type '{$column['type']}'. Valid types: " . implode(', ', self::VALID_COLUMN_TYPES);
            }

            if ($column['type'] === 'foreignId' && empty($column['foreignModel'])) {
                $errors[] = "Column '{$name}' is foreignId but missing 'foreign_model'.";
            }
        }

        return $errors;
    }

    /**
     * Validate permission definitions.
     */
    protected function validatePermissions(array $permissions, array $validRoles, array $columnNames): array
    {
        $errors = [];
        $warnings = [];

        foreach ($permissions as $role => $definition) {
            // Check role exists in _roles.yaml (if roles were provided)
            if (!empty($validRoles) && !array_key_exists($role, $validRoles)) {
                $errors[] = "Permission defined for unknown role '{$role}'. Define it in _roles.yaml first.";
            }

            // Validate actions
            foreach ($definition['actions'] as $action) {
                if (!in_array($action, self::VALID_ACTIONS)) {
                    $errors[] = "Role '{$role}' has invalid action '{$action}'. Valid actions: " . implode(', ', self::VALID_ACTIONS);
                }
            }

            // Validate field lists reference real columns (if not wildcard)
            foreach (['show_fields', 'create_fields', 'update_fields'] as $fieldType) {
                $fields = $definition[$fieldType] ?? [];
                if ($fields !== ['*'] && !empty($columnNames)) {
                    foreach ($fields as $field) {
                        if ($field !== 'id' && !in_array($field, $columnNames)) {
                            $warnings[] = "Role '{$role}' references unknown field '{$field}' in {$fieldType}.";
                        }
                    }
                }
            }

            // Check for conflicts: field in both show_fields and hidden_fields
            $showFields = $definition['show_fields'] ?? [];
            $hiddenFields = $definition['hidden_fields'] ?? [];
            if ($showFields !== ['*'] && !empty($hiddenFields)) {
                $conflicts = array_intersect($showFields, $hiddenFields);
                if (!empty($conflicts)) {
                    $warnings[] = "Role '{$role}' has fields in both show_fields and hidden_fields: " . implode(', ', $conflicts);
                }
            }

            // Warn if role has create/update fields but no store/update action
            $actions = $definition['actions'] ?? [];
            $createFields = $definition['create_fields'] ?? [];
            $updateFields = $definition['update_fields'] ?? [];

            if (!empty($createFields) && $createFields !== ['*'] && !in_array('store', $actions)) {
                $warnings[] = "Role '{$role}' has create_fields but no 'store' action.";
            }
            if (!empty($updateFields) && $updateFields !== ['*'] && !in_array('update', $actions)) {
                $warnings[] = "Role '{$role}' has update_fields but no 'update' action.";
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate options.
     */
    protected function validateOptions(array $options): array
    {
        $errors = [];

        if (isset($options['except_actions'])) {
            foreach ($options['except_actions'] as $action) {
                if (!in_array($action, self::VALID_ACTIONS)) {
                    $errors[] = "Invalid except_action: '{$action}'.";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate relationships.
     */
    protected function validateRelationships(array $relationships): array
    {
        $errors = [];
        $validTypes = ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany'];

        foreach ($relationships as $rel) {
            if (!isset($rel['type'])) {
                $errors[] = 'Relationship missing type.';
                continue;
            }

            if (!in_array($rel['type'], $validTypes)) {
                $errors[] = "Invalid relationship type '{$rel['type']}'. Valid: " . implode(', ', $validTypes);
            }

            if (empty($rel['model'])) {
                $errors[] = "Relationship of type '{$rel['type']}' missing model.";
            }
        }

        return $errors;
    }

    /**
     * Extract column names from normalized columns array.
     */
    protected function getColumnNames(array $columns): array
    {
        return array_map(fn($col) => $col['name'], $columns);
    }
}
