<?php

namespace Rhino\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder;

class ScopedQueryBuilder extends Builder
{
    public function __construct($connection, $grammar, $processor, Model $model, Scope $scope = null)
    {
        parent::__construct($connection, $grammar, $processor);

        if ($scope instanceof Scope) {
            $scope->apply($this, $model);
        }
    }
}
