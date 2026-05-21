<?php

namespace Rhino\Traits;

trait HasAutoScope
{
    protected static function bootHasAutoScope(): void
    {
        $modelName = class_basename(static::class);
        $scopeClass = "App\\Models\\Scopes\\{$modelName}Scope";

        if (class_exists($scopeClass)) {
            static::addGlobalScope(new $scopeClass);
        }
    }
}
