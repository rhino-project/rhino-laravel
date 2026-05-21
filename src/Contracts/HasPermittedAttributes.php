<?php

namespace Rhino\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Defines per-user attribute permissions for Rhino resources.
 *
 * Implement this interface on your Policy to control which attributes
 * each user can see (show) and write (create/update). This replaces
 * the model-level $validationRulesStore/$validationRulesUpdate allowlisting
 * with a cleaner separation of concerns:
 *
 *   - **Policy** = who can see/write what (permissions)
 *   - **Model**  = how to validate field formats (validation rules only)
 *
 * Return `['*']` to allow all attributes (the default in ResourcePolicy).
 * Return a specific list to restrict to only those attributes.
 */
interface HasPermittedAttributes
{
    /**
     * Attributes the user is allowed to see in show/index responses.
     *
     * Return `['*']` to allow all attributes (default).
     * Return a specific list (e.g. `['id', 'title', 'status']`) to restrict.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function permittedAttributesForShow(?Authenticatable $user): array;

    /**
     * Attributes that should be hidden from show/index responses.
     *
     * These are merged with the model's base hidden columns and any
     * static $additionalHiddenColumns. Returning an empty array means
     * no additional columns are hidden beyond the defaults.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function hiddenAttributesForShow(?Authenticatable $user): array;

    /**
     * Attributes the user is allowed to send when creating a resource.
     *
     * Return `['*']` to allow all fillable attributes (default).
     * Return a specific list to restrict. Any field not in this list
     * will trigger a 403 Forbidden response.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function permittedAttributesForCreate(?Authenticatable $user): array;

    /**
     * Attributes the user is allowed to send when updating a resource.
     *
     * Return `['*']` to allow all fillable attributes (default).
     * Return a specific list to restrict. Any field not in this list
     * will trigger a 403 Forbidden response.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function permittedAttributesForUpdate(?Authenticatable $user): array;
}
