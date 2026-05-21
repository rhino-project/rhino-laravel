<?php

namespace Rhino\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Boot the trait.
     *
     * Listen for the creating event of a model,
     * then set the UUID.
     */
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
