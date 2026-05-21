<?php

namespace Rhino\Scopes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ScopedDB extends DB
{
    public static function table($table, $as = null, $connection = null): ScopedQueryBuilder
    {
        $scopedTables = ScopedDB::getScopedTables();

        if (array_key_exists($table, $scopedTables)) {
            $query = new ScopedQueryBuilder(
                static::getFacadeRoot()->connection($connection),
                null,
                null,
                $scopedTables[$table]['model'],
                $scopedTables[$table]['scope']
            );

            $query->from($table, $as);
        } else {
            throw new \Exception('Scoped table \'' . $table . '\' not found');
        }

        return $query;
    }

    public static function getScopedTables(): array
    {
        $scopesPath = app_path('Models/Scopes');
        $scopeFiles = File::files($scopesPath);

        $scopedTables = [];

        foreach ($scopeFiles as $scopeFile) {
            $baseName = basename($scopeFile->getFilename(), 'Scope.php');
            if(File::exists($scopesPath.'/'.$baseName.'Scope.php')) {
                $modelName = "App\\Models\\".$baseName;
                $scopeName = "App\\Models\\Scopes\\".$baseName."Scope";
                $scopedTables[strtolower($baseName.'s')] = [
                    'scope' => new $scopeName(),
                    'model' => new $modelName(),
                ];
            }
        }

        return $scopedTables;
    }
}
