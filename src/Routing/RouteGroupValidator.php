<?php

namespace Rhino\Routing;

use Rhino\Exceptions\RouteGroupConflictException;

/**
 * Validates the configured route groups at boot time, before any routes are
 * registered, and throws a RouteGroupConflictException when two groups would
 * silently shadow each other.
 *
 * A route group's routing identity is the pair (host-set, prefix), per model.
 * Two groups conflict when ALL of the following hold:
 *
 *   1. Their host-sets intersect. A group with no `domain` (null or '') matches
 *      EVERY host (a wildcard), so it intersects with anything. Two groups with
 *      an identical, non-empty `domain` pattern also intersect. Two groups with
 *      different non-empty domain patterns are treated as disjoint.
 *   2. They share the same effective prefix (null and '' are the same "root").
 *   3. Their model sets overlap ('*' expands to every registered model, so it
 *      overlaps with everything; explicit slug lists overlap on intersection).
 *
 * This encodes the rule: with a distinguishing domain, the prefix is optional
 * (root is fine, the host disambiguates); without a domain, the prefix is the
 * only disambiguator, so two or more overlapping groups must use distinct
 * prefixes.
 *
 * Note: this is a conservative, static check. Exotic cross-pattern overlaps
 * (e.g. a literal host that also happens to satisfy another group's
 * '{param}.example.com') are not statically detected.
 */
class RouteGroupValidator
{
    /**
     * @param  array<string, array<string, mixed>>  $routeGroups
     * @param  array<string, class-string>  $allModels
     */
    public static function validate(array $routeGroups, array $allModels): void
    {
        self::assertSingleDefaultAuthGroup($routeGroups);

        $keys = array_keys($routeGroups);
        $count = count($keys);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $aKey = $keys[$i];
                $bKey = $keys[$j];
                $a = $routeGroups[$aKey];
                $b = $routeGroups[$bKey];

                if (!self::hostSetsIntersect($a, $b)) {
                    continue;
                }

                if (self::normalizePrefix($a) !== self::normalizePrefix($b)) {
                    continue;
                }

                $sharedModels = self::overlappingModels($a, $b, $allModels);
                if ($sharedModels === []) {
                    continue;
                }

                throw new RouteGroupConflictException(self::message($aKey, $bKey, $a, $b, $sharedModels));
            }
        }
    }

    /**
     * Identify auth-enabled groups that have an empty prefix AND no domain.
     *
     * Such a group registers an auth route set (e.g. POST auth/login on every
     * host) that is byte-for-byte identical to the legacy unprefixed /auth/*
     * set — they are routing-indistinguishable. Exactly one such group is the
     * supported case (it simply IS the default/legacy auth, see §11.1), but two
     * or more are genuinely ambiguous: there is no way to tell which group a
     * login on the bare path belongs to. Fail fast at boot.
     *
     * @param  array<string, array<string, mixed>>  $routeGroups
     */
    protected static function assertSingleDefaultAuthGroup(array $routeGroups): void
    {
        $offenders = [];

        foreach ($routeGroups as $key => $group) {
            if ($key === 'public' || empty($group['auth'])) {
                continue;
            }

            if (self::normalizePrefix($group) === '' && self::normalizeDomain($group) === null) {
                $offenders[] = $key;
            }
        }

        if (count($offenders) < 2) {
            return;
        }

        $names = implode(", ", array_map(fn ($k) => "'{$k}'", $offenders));

        throw new RouteGroupConflictException(sprintf(
            "Route groups %s are all auth-enabled with an empty prefix and no domain, so their auth "
            . "routes (e.g. auth/login) are indistinguishable from each other and from the legacy "
            . "/auth/* set — a login could not be attributed to a single group. Give all but one of "
            . "them a distinct 'prefix' or 'domain'.",
            $names
        ));
    }

    /**
     * @param  array<string, mixed>  $group
     */
    protected static function normalizePrefix(array $group): string
    {
        return (string) ($group['prefix'] ?? '');
    }

    /**
     * A null/empty domain means "any host"; otherwise the trimmed pattern.
     *
     * @param  array<string, mixed>  $group
     */
    protected static function normalizeDomain(array $group): ?string
    {
        $domain = $group['domain'] ?? null;

        if ($domain === null || $domain === '') {
            return null;
        }

        return (string) $domain;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected static function hostSetsIntersect(array $a, array $b): bool
    {
        $da = self::normalizeDomain($a);
        $db = self::normalizeDomain($b);

        // A wildcard host (no domain) intersects with any other host-set.
        if ($da === null || $db === null) {
            return true;
        }

        // Two explicit domain patterns intersect only when identical.
        return $da === $db;
    }

    /**
     * Resolve a group's `models` to a concrete set of slugs.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, class-string>  $allModels
     * @return array<int, string>
     */
    protected static function resolveModels(array $group, array $allModels): array
    {
        $models = $group['models'] ?? '*';

        if ($models === '*') {
            return array_keys($allModels);
        }

        return array_values((array) $models);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, class-string>  $allModels
     * @return array<int, string>
     */
    protected static function overlappingModels(array $a, array $b, array $allModels): array
    {
        $ma = self::resolveModels($a, $allModels);
        $mb = self::resolveModels($b, $allModels);

        return array_values(array_intersect($ma, $mb));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<int, string>  $sharedModels
     */
    protected static function message(string $aKey, string $bKey, array $a, array $b, array $sharedModels): string
    {
        $prefix = self::normalizePrefix($a);
        $prefixLabel = $prefix === '' ? '(root)' : "'{$prefix}'";

        $da = self::normalizeDomain($a);
        $db = self::normalizeDomain($b);
        $domainLabel = ($da === null && $db === null)
            ? 'no domain'
            : sprintf("domains [%s, %s]", $da ?? 'any', $db ?? 'any');

        $models = implode(', ', $sharedModels);

        return sprintf(
            "Route groups '%s' and '%s' conflict: they share prefix %s with %s and overlapping models (%s), "
            . "so one would silently shadow the other. Give them distinct prefixes, or distinguish them with "
            . "different 'domain' values, or make their 'models' disjoint.",
            $aKey,
            $bKey,
            $prefixLabel,
            $domainLabel,
            $models
        );
    }
}
