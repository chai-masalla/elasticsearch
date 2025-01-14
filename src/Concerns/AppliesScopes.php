<?php

declare(strict_types=1);

namespace Matchory\Elasticsearch\Concerns;

use BadMethodCallException;
use Closure;
use Illuminate\Support\Arr;
use Matchory\Elasticsearch\Builder;
use Matchory\Elasticsearch\Interfaces\ScopeInterface;
use Matchory\Elasticsearch\Model;

use function array_keys;
use function array_unshift;
use function array_values;
use function get_class;
use function is_array;
use function is_int;
use function is_string;
use function method_exists;

trait AppliesScopes
{
    /**
     * Holds all scopes removed from the query.
     *
     * @var array<array-key, string>
     */
    protected array $removedScopes = [];

    /**
     * Holds all scopes applied to the query.
     *
     * @var array<string, Closure|ScopeInterface>
     */
    protected array $scopes = [];

    abstract public function getModel(): Model|null;

    /**
     * Apply the scopes to the Elasticsearch query instance and return it.
     *
     * @return $this
     * @internal The library will call this method before invoking the query
     *           automatically - you should not need to call it manually.
     */
    public function applyScopes(): self
    {
        if ( ! $this->scopes) {
            return $this;
        }

        $query = clone $this;

        foreach ($this->scopes as $identifier => $scope) {
            if ( ! isset($query->scopes[$identifier])) {
                continue;
            }

            $query->callScope(function (Builder $query) use ($scope) {
                // If the scope is a Closure we will just go ahead and call the
                // scope with the builder instance.
                if ($scope instanceof Closure) {
                    $scope($query);
                }

                // If the scope is a scope object, we will call the apply method
                // on this scope passing in the query and the model instance.
                // After we run all of these scopes we will return the query
                // instance to the outside caller.
                if ($scope instanceof ScopeInterface) {
                    $scope->apply($query, $this->getModel());
                }
            });
        }

        return $query;
    }

    /**
     * Determine if the given model has a scope.
     *
     * @param string $scope
     *
     * @return bool
     */
    public function hasNamedScope(string $scope): bool
    {
        return $this->getModel()->hasNamedScope($scope);
    }

    /**
     * Get an array of global scopes that were removed from the query.
     *
     * @return string[]
     */
    public function removedScopes(): array
    {
        return $this->removedScopes;
    }

    /**
     * Call the given local model scopes.
     *
     * @param array<string|int, array|string>|string $scopes Scopes to call.
     *
     * @return $this
     * @throws BadMethodCallException
     */
    public function scopes(array|string $scopes): self
    {
        $query = $this;

        foreach (Arr::wrap($scopes) as $scope => $parameters) {
            // If the scope key is an integer, then the scope was passed as the
            // value and the parameter list is empty, so we will format the
            // scope name and these parameters here. Then, we'll be ready to
            // call the scope on the model.
            if (is_int($scope)) {
                [$scope, $parameters] = [$parameters, []];
            }

            // Next we'll pass the scope callback to the callScope method which
            // will take care of grouping the conditions properly so the logical
            // order doesn't get messed up when adding scopes.
            // Then we'll return out the query.
            $query = $query->callNamedScope(
                $scope,
                (array)$parameters
            );
        }

        return $query;
    }

    /**
     * Register a new global scope.
     *
     * @param string                 $identifier
     * @param Closure|ScopeInterface $scope
     *
     * @return $this
     */
    public function withGlobalScope(
        string $identifier,
        ScopeInterface|Closure $scope
    ): self {
        $this->scopes[$identifier] = $scope;

        if (method_exists($scope, 'extend')) {
            $scope->extend($this);
        }

        return $this;
    }

    /**
     * Remove a registered global scope.
     *
     * @param string|ScopeInterface $scope
     *
     * @return $this
     */
    public function withoutGlobalScope(ScopeInterface|string $scope): self
    {
        if ( ! is_string($scope)) {
            $scope = get_class($scope);
        }

        unset($this->scopes[$scope]);

        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Remove all or passed registered global scopes.
     *
     * @param array<string|ScopeInterface>|null $scopes
     *
     * @return $this
     */
    public function withoutGlobalScopes(array $scopes = null): self
    {
        if ( ! is_array($scopes)) {
            $scopes = array_keys($this->scopes);
        }

        foreach ($scopes as $scope) {
            $this->withoutGlobalScope($scope);
        }

        return $this;
    }

    /**
     * Apply the given named scope on the current query instance.
     *
     * @param string $scope
     * @param array  $parameters
     *
     * @return $this
     * @throws BadMethodCallException
     */
    protected function callNamedScope(
        string $scope,
        array $parameters = []
    ): self {
        return $this->callScope(function (...$parameters) use ($scope) {
            return $this->getModel()->callNamedScope(
                $scope,
                $parameters
            );
        }, $parameters);
    }

    /**
     * Apply the given scope on the current builder instance.
     *
     * @param callable $scope
     * @param array    $parameters
     *
     * @return $this
     */
    protected function callScope(callable $scope, array $parameters = []): self
    {
        array_unshift($parameters, $this);

        $scope(...array_values($parameters)) ?? $this;

        return $this;
    }
}
