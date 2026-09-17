<?php

namespace Rhino\Http\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Rhino\Support\TenantExistsRules;

/**
 * Base class for Rhino request classes.
 *
 * A request class owns the whole shape/format contract of ONE action of ONE
 * model. Rhino discovers `{Model}StoreRequest` and `{Model}UpdateRequest` in
 * the namespace configured by `rhino.requests.namespace` (default
 * `App\Http\Requests`), or reads an explicit class from
 * `rhino.requests.map.{slug}.{store|update}`.
 *
 *     namespace App\Http\Requests;
 *
 *     use Rhino\Http\Requests\ResourceRequest;
 *
 *     class TaskStoreRequest extends ResourceRequest
 *     {
 *         public function authorize(): bool
 *         {
 *             return $this->routeGroup() !== 'public';
 *         }
 *
 *         public function prepare(array $input): array
 *         {
 *             $input['title'] = trim($input['title'] ?? '');
 *
 *             return $input;
 *         }
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'title' => 'required|string|max:255',
 *                 'project_id' => 'required|integer|exists:projects,id',
 *             ];
 *         }
 *     }
 *
 * Context is reached through the helpers below rather than through a `$ctx`
 * argument: `FormRequest` invokes `rules()` and `authorize()` through the
 * container (`$this->container->call(...)`), so a declared parameter would be
 * resolved as a dependency and blow up with a BindingResolutionException.
 * `authorize()`, `rules()`, `messages()`, `attributes()`, `after()` and
 * `withValidator()` therefore keep their native zero-argument signatures.
 *
 * What gets persisted is `validated()` — the keys covered by a rule that
 * passed. A field with no rule is silently dropped, including a field that
 * `prepare()` added. This fails closed: the request class is the field filter
 * even when the policy permits `['*']`.
 *
 * Rhino applies two things on top of the declared rules:
 *  - `exists:` rules are scoped to the current organization (directly, or
 *    through a walked FK chain for indirectly-owned models);
 *  - `organization_id` is removed from the ruleset in tenant context, because
 *    it is framework-managed and applied after validation.
 */
abstract class ResourceRequest extends FormRequest
{
    /**
     * The resolved tenant, or null outside tenant context.
     *
     * @var mixed
     */
    protected $rhinoOrganization = null;

    /**
     * The matched route's `route_group` default, or null.
     */
    protected ?string $rhinoRouteGroup = null;

    /**
     * 'store' or 'update'.
     */
    protected string $rhinoAction = 'store';

    /**
     * The PRE-UPDATE record on update; null on store.
     */
    protected ?Model $rhinoRecord = null;

    /**
     * Fields whose value is resolved server-side after validation, so no rule
     * of theirs may run. Today this is the nested endpoint's cross-operation
     * "$N.field" references.
     *
     * @var array<int, string>
     */
    protected array $rhinoExcludedFields = [];

    /**
     * Inject the Rhino context. Called by the controller after the request is
     * copied and before validateResolved() runs — never call it yourself.
     *
     * @param  mixed  $organization
     * @return $this
     */
    public function setRhinoContext(
        ?Authenticatable $user,
        $organization,
        ?string $routeGroup,
        string $action,
        ?Model $record
    ): static {
        $this->rhinoOrganization = $organization;
        $this->rhinoRouteGroup = $routeGroup;
        $this->rhinoAction = $action;
        $this->rhinoRecord = $record;

        // user() is FormRequest's own accessor; Rhino pins it to the user the
        // controller authenticated (the sanctum guard), so a request class does
        // not have to know which guard served the route.
        $this->setUserResolver(static fn () => $user);

        return $this;
    }

    /**
     * Declare fields whose value Rhino resolves after validation, so that the
     * rules declared for them are skipped entirely — including `required`.
     *
     * Used by POST /nested for cross-operation "$N.field" references: the
     * placeholder is stripped from the input before the request class sees it
     * (a class must never be able to reject one) and merged back into the write
     * payload afterwards, so a rule on that field would otherwise fail for a
     * value the client did supply. The legacy path does the same through
     * validateForAction()'s $excludeFields argument.
     *
     * Called by the controller — never call it yourself.
     *
     * @param  array<int, string>  $fields
     * @return $this
     */
    public function setRhinoExcludedFields(array $fields): static
    {
        $this->rhinoExcludedFields = $fields;

        return $this;
    }

    /**
     * The organization the request is being served for, or null outside a
     * tenant route group (and in single-tenant apps).
     *
     * @return mixed
     */
    public function organization()
    {
        return $this->rhinoOrganization;
    }

    /**
     * The matched route's route group, or null when the route carries none.
     */
    public function routeGroup(): ?string
    {
        return $this->rhinoRouteGroup;
    }

    /**
     * 'store' or 'update'. A class registered for both actions can branch on it.
     */
    public function action(): string
    {
        return $this->rhinoAction;
    }

    /**
     * The record being updated, in its PRE-UPDATE state. Null on store, and
     * null for a nested update operation whose id matched no row in this
     * organization (the nested authorization step reports that separately).
     */
    public function record(): ?Model
    {
        return $this->rhinoRecord;
    }

    /**
     * Normalize the input before anything else sees it.
     *
     * The returned array replaces the request input for authorize(), the rules
     * and the write payload. It runs AFTER the policy's forbidden-field check,
     * which sees exactly what the client sent — so prepare() can never launder
     * a field past the policy. Everything it adds is server-authored and
     * trusted; never copy a client value into a key the policy denies.
     *
     * Only keys also covered by a rule end up persisted.
     */
    public function prepare(array $input): array
    {
        return $input;
    }

    // ------------------------------------------------------------------
    // Internal wiring — not override points.
    // ------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    protected function prepareForValidation(): void
    {
        $prepared = $this->prepare($this->all());

        if (is_array($prepared)) {
            $this->replace($prepared);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function validationRules()
    {
        $rules = parent::validationRules();

        if ($this->rhinoExcludedFields !== []) {
            $rules = array_diff_key($rules, array_flip($this->rhinoExcludedFields));
        }

        return TenantExistsRules::scope($rules, $this->organization());
    }
}
