<?php

namespace Rhino\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rhino\Traits\HasAutoScope;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

/**
 * RhinoModel — convenience base class for Rhino-powered Eloquent models.
 *
 * Extends Laravel's `Model` and includes the most commonly needed traits for
 * Rhino's automatic REST API generation. Subclass this instead of `Model` to
 * get soft deletes, validation, column hiding, and auto-scopes out of the box.
 *
 * ## Quick Start
 *
 * ```php
 * use Rhino\Models\RhinoModel;
 *
 * class Post extends RhinoModel
 * {
 *     protected $fillable = ['title', 'content', 'status'];
 *
 *     protected $validationRules = [
 *         'title'   => 'required|string|max:255',
 *         'content' => 'string',
 *         'status'  => 'string|in:draft,published',
 *     ];
 *
 *     protected $validationRulesStore = ['title', 'content'];
 *     protected $validationRulesUpdate = ['title', 'content', 'status'];
 *
 *     public static $allowedFilters  = ['status', 'user_id'];
 *     public static $allowedSorts    = ['created_at', 'title'];
 *     public static $defaultSort     = '-created_at';
 *     public static $allowedIncludes = ['user', 'comments'];
 *     public static $allowedSearch   = ['title', 'content'];
 *     public static $allowedFields   = ['id', 'title', 'content', 'status'];
 * }
 * ```
 *
 * ## Included Traits
 *
 * | Trait           | Purpose                                                       |
 * |-----------------|---------------------------------------------------------------|
 * | HasFactory      | Laravel factory support for testing                           |
 * | SoftDeletes     | `deleted_at` column, trash/restore/force-delete endpoints     |
 * | HasValidation   | Role-based validation rules (pipe-delimited format)           |
 * | HidableColumns  | Dynamic column hiding from API responses                      |
 * | HasAutoScope    | Auto-discovery of `App\Models\Scopes\{Model}Scope` classes   |
 *
 * ## Optional Traits (add manually when needed)
 *
 * | Trait                  | Purpose                                    |
 * |------------------------|--------------------------------------------|
 * | HasAuditTrail          | Automatic change logging to `audit_logs`   |
 * | HasUuid                | Auto-generated UUID on creation            |
 * | BelongsToOrganization  | Multi-tenant organization scoping          |
 * | HasPermissions         | Permission checking (User model only)      |
 * | ViewModelHelpers       | Currency formatting helpers                |
 *
 * ```php
 * use Rhino\Models\RhinoModel;
 * use Rhino\Traits\HasAuditTrail;
 * use Rhino\Traits\BelongsToOrganization;
 *
 * class Article extends RhinoModel
 * {
 *     use HasAuditTrail, BelongsToOrganization;
 *
 *     protected $fillable = ['title', 'content', 'status', 'user_id'];
 *
 *     protected $validationRules = [
 *         'title'   => 'required|string|max:255',
 *         'content' => 'string',
 *     ];
 *
 *     // Role-keyed store rules (admin can set more fields)
 *     protected $validationRulesStore = [
 *         'admin' => ['title' => 'required', 'content' => 'required', 'status' => 'nullable'],
 *         '*'     => ['title' => 'required', 'content' => 'required'],
 *     ];
 *
 *     protected $validationRulesUpdate = [
 *         'admin' => ['title' => 'sometimes', 'content' => 'sometimes', 'status' => 'nullable'],
 *         '*'     => ['title' => 'sometimes', 'content' => 'sometimes'],
 *     ];
 *
 *     public static $allowedFilters  = ['status', 'user_id'];
 *     public static $allowedSorts    = ['created_at', 'title'];
 *     public static $defaultSort     = '-created_at';
 *     public static $allowedIncludes = ['user', 'comments'];
 *     public static $allowedSearch   = ['title', 'content'];
 *     public static $allowedFields   = ['id', 'title', 'content', 'status'];
 *
 *     public static bool $paginationEnabled = true;
 *     protected $perPage = 20;
 *
 *     protected $auditExclude = ['internal_notes'];
 * }
 * ```
 *
 * @see \Rhino\Traits\HasValidation
 * @see \Rhino\Traits\HidableColumns
 * @see \Rhino\Traits\HasAutoScope
 * @see \Illuminate\Database\Eloquent\SoftDeletes
 */
abstract class RhinoModel extends Model
{
    use HasFactory, SoftDeletes, HasValidation, HidableColumns, HasAutoScope;

    // =========================================================================
    // VALIDATION (provided by HasValidation)
    // =========================================================================

    /**
     * Base validation rules for all fields (pipe-delimited, Laravel format).
     *
     * These format rules are applied for every action & role. Store/update rules
     * layer on top of these.
     *
     * @example
     * ```php
     * protected $validationRules = [
     *     'title'   => 'required|string|max:255',
     *     'content' => 'string',
     *     'status'  => 'string|in:draft,published,archived',
     *     'email'   => 'email|max:255',
     *     'score'   => 'integer|min:0|max:100',
     * ];
     * ```
     *
     * @var array<string, string>
     */
    protected $validationRules = [];

    /**
     * Store (create) validation — controls which fields are accepted on POST.
     *
     * **This property acts as a field allowlist.** Only fields listed here will
     * be present in the validated data passed to `Model::create()`. Any field
     * sent in the request but **not listed** for the current role (or in the
     * flat array) is **silently ignored** — it will not reach the database.
     *
     * Supports two formats:
     *
     * **Legacy format** (flat array of field names — picks from $validationRules):
     * ```php
     * protected $validationRulesStore = ['title', 'content'];
     * // A request with { title, content, status } → only title and content are accepted
     * ```
     *
     * **Role-keyed format** (per-role overrides with `'*'` wildcard fallback):
     * ```php
     * protected $validationRulesStore = [
     *     'admin'  => ['title' => 'required', 'content' => 'required', 'status' => 'nullable'],
     *     'editor' => ['title' => 'required', 'content' => 'required'],
     *     '*'      => ['title' => 'required'],  // fallback for unknown roles
     * ];
     * // An editor sending { title, content, status } → status is silently dropped
     * // An admin sending the same payload → all three fields are accepted
     * ```
     *
     * Role resolution uses `HasPermissions::getRoleSlugForValidation()`.
     *
     * @var array
     */
    protected $validationRulesStore = [];

    /**
     * Update validation — controls which fields are accepted on PUT/PATCH.
     *
     * Same format and allowlist behavior as `$validationRulesStore`: fields not
     * listed for the current role are **silently dropped** from the validated
     * data and will not be passed to `Model::update()`.
     *
     * @example
     * ```php
     * // Legacy:
     * protected $validationRulesUpdate = ['title', 'content', 'status'];
     *
     * // Role-keyed:
     * protected $validationRulesUpdate = [
     *     'admin' => ['title' => 'sometimes', 'status' => 'nullable', 'is_published' => 'nullable'],
     *     '*'     => ['title' => 'sometimes', 'content' => 'sometimes'],
     * ];
     * // A non-admin sending { title, is_published } → is_published is silently dropped
     * ```
     *
     * @var array
     */
    protected $validationRulesUpdate = [];

    /**
     * Custom validation error messages (optional).
     *
     * Follows the standard Laravel validation message format.
     *
     * @example
     * ```php
     * protected $validationRulesMessages = [
     *     'title.required' => 'Every post needs a title.',
     *     'title.max'      => 'Post title cannot exceed 255 characters.',
     *     'status.in'      => 'Status must be one of: draft, published, archived.',
     * ];
     * ```
     *
     * @var array<string, string>
     */
    protected $validationRulesMessages = [];

    // =========================================================================
    // QUERY BUILDER — Filtering, Sorting, Search, Includes, Fields
    // =========================================================================

    /**
     * Filterable columns.
     *
     * Controls which fields can be filtered via `?filter[field]=value`.
     * Only whitelisted fields are accepted — unlisted fields are silently ignored.
     *
     * @example
     * ```php
     * public static $allowedFilters = ['status', 'user_id', 'category_id', 'is_published'];
     * ```
     *
     * Query: `GET /api/posts?filter[status]=published&filter[user_id]=5`
     *
     * @var array<string>
     */
    public static $allowedFilters = [];

    /**
     * Sortable columns.
     *
     * Controls which fields can be used for sorting via `?sort=field`.
     * Prefix with `-` for descending order.
     *
     * @example
     * ```php
     * public static $allowedSorts = ['created_at', 'title', 'status', 'updated_at'];
     * ```
     *
     * Query: `GET /api/posts?sort=-created_at` or `GET /api/posts?sort=title`
     *
     * @var array<string>
     */
    public static $allowedSorts = [];

    /**
     * Default sort column and direction (applied when no `?sort` is provided).
     *
     * Prefix with `-` for descending.
     *
     * @example
     * ```php
     * public static $defaultSort = '-created_at';  // newest first
     * public static $defaultSort = 'title';         // alphabetical
     * ```
     *
     * @var string
     */
    public static $defaultSort = 'created_at';

    /**
     * Selectable columns (sparse fieldsets).
     *
     * Controls which columns can be selected via `?fields[model]=field1,field2`.
     * Limits the payload size by returning only requested columns.
     *
     * @example
     * ```php
     * public static $allowedFields = ['id', 'title', 'status', 'created_at', 'user_id'];
     * ```
     *
     * Query: `GET /api/posts?fields[posts]=id,title,status`
     *
     * @var array<string>
     */
    public static $allowedFields = [];

    /**
     * Eager-loadable relationships.
     *
     * Controls which relationships can be included via `?include=relation`.
     * Must correspond to defined Eloquent relationships on the model.
     * Supports nested includes: `'comments.user'`.
     *
     * @example
     * ```php
     * public static $allowedIncludes = ['user', 'comments', 'tags', 'comments.user'];
     * ```
     *
     * Query: `GET /api/posts?include=user,comments`
     *
     * @var array<string>
     */
    public static $allowedIncludes = [];

    /**
     * Searchable columns (full-text search across multiple fields).
     *
     * When `?search=term` is used, Rhino performs a case-insensitive LIKE
     * search across all listed fields. Supports dot notation for relationships.
     *
     * @example
     * ```php
     * public static $allowedSearch = ['title', 'content', 'excerpt', 'user.name'];
     * ```
     *
     * Query: `GET /api/posts?search=laravel`
     *
     * @var array<string>
     */
    public static $allowedSearch = [];

    // =========================================================================
    // PAGINATION
    // =========================================================================

    /**
     * Whether pagination is enabled for the index endpoint.
     *
     * When `true`, the API returns paginated results with X-* headers:
     * `X-Current-Page`, `X-Last-Page`, `X-Per-Page`, `X-Total`.
     *
     * When `false`, the API returns all records (use with caution on large tables).
     * Even when `false`, clients can request pagination via `?per_page=N`.
     *
     * @example
     * ```php
     * public static bool $paginationEnabled = true;
     * ```
     *
     * @var bool
     */
    public static bool $paginationEnabled = false;

    // Note: $perPage is inherited from Illuminate\Database\Eloquent\Model.
    // Override it on your model:
    //   protected $perPage = 25;

    // =========================================================================
    // MIDDLEWARE
    // =========================================================================

    /**
     * Middleware applied to ALL routes for this model.
     *
     * @example
     * ```php
     * public static array $middleware = ['throttle:60,1', 'auth:sanctum'];
     * ```
     *
     * @var array<string>
     */
    public static array $middleware = [];

    /**
     * Middleware applied to specific actions only.
     *
     * Keys are action names: `'index'`, `'show'`, `'store'`, `'update'`, `'destroy'`.
     *
     * @example
     * ```php
     * public static array $middlewareActions = [
     *     'store'   => ['verified'],
     *     'update'  => ['verified'],
     *     'destroy' => ['admin'],
     * ];
     * ```
     *
     * @var array<string, array<string>>
     */
    public static array $middlewareActions = [];

    // =========================================================================
    // ROUTE EXCLUSION
    // =========================================================================

    /**
     * CRUD actions to exclude from route registration.
     *
     * Valid values: `'index'`, `'show'`, `'store'`, `'update'`, `'destroy'`,
     * `'trashed'`, `'restore'`, `'forceDelete'`.
     *
     * @example
     * ```php
     * // Disable delete endpoints entirely
     * public static array $exceptActions = ['destroy', 'forceDelete'];
     *
     * // Read-only API
     * public static array $exceptActions = ['store', 'update', 'destroy'];
     * ```
     *
     * @var array<string>
     */
    public static array $exceptActions = [];

    // =========================================================================
    // HIDDEN COLUMNS (provided by HidableColumns)
    // =========================================================================

    /**
     * Additional columns to hide from API responses (on top of base defaults).
     *
     * Base hidden columns (always hidden): `password`, `remember_token`,
     * `created_at`, `updated_at`, `deleted_at`, `email_verified_at`,
     * `has_temporary_password`.
     *
     * For per-user column hiding, override `hiddenColumns()` on your Policy.
     *
     * @example
     * ```php
     * protected static $additionalHiddenColumns = ['api_token', 'stripe_id', 'internal_notes'];
     * ```
     *
     * @var array<string>
     */
    protected static $additionalHiddenColumns = [];

    // =========================================================================
    // MULTI-TENANCY / OWNERSHIP (used when BelongsToOrganization trait is added)
    // =========================================================================

    /**
     * @internal Auto-detected from BelongsTo relationships. Do not set manually.
     */
    public static string $owner = '';

    // =========================================================================
    // AUDIT TRAIL (requires `use HasAuditTrail;`)
    // =========================================================================

    /**
     * Fields to exclude from audit log snapshots.
     *
     * When using the `HasAuditTrail` trait, old/new values are recorded
     * for every create/update/delete. Exclude sensitive fields here.
     *
     * @example
     * ```php
     * protected $auditExclude = ['password', 'remember_token', 'api_key', 'secret'];
     * ```
     *
     * Access audit logs: `$post->auditLogs()->latest()->get();`
     *
     * @var array<string>
     */
    protected $auditExclude = ['password', 'remember_token'];
}
