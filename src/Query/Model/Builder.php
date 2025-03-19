<?php

namespace LdapRecord\Query\Model;

use Closure;
use DateTime;
use LdapRecord\Models\Attributes\Guid;
use LdapRecord\Models\Collection;
use LdapRecord\Models\Model;
use LdapRecord\Models\ModelNotFoundException;
use LdapRecord\Models\Scope;
use LdapRecord\Models\Types\ActiveDirectory;
use LdapRecord\Query\Builder as QueryBuilder;
use LdapRecord\Query\MultipleObjectsFoundException;
use LdapRecord\Support\ForwardsCalls;
use UnexpectedValueException;

/**
 * @mixin \LdapRecord\Query\Builder
 */
class Builder
{
    use ForwardsCalls;

    /**
     * The global scopes to be applied.
     */
    protected array $scopes = [];

    /**
     * The removed global scopes.
     */
    protected array $removedScopes = [];

    /**
     * The applied global scopes.
     */
    protected array $appliedScopes = [];

    /**
     * The methods that should be returned from query builder.
     *
     * @var string[]
     */
    protected $passthru = [
        'getdn',
        'gettype',
        'getcache',
        'getbasedn',
        'getselects',
        'getconnection',
        'getunescapedquery',
        'escape',
        'exists',
        'existsor',
        'doesntexist',
    ];

    /**
     * Constructor.
     */
    public function __construct(
        protected Model $model,
        protected QueryBuilder $query,
    ) {
        $this->query->select([$this->model->getGuidKey(), '*']);
    }

    /**
     * Dynamically handle calls into the query instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->model, $scope = 'scope'.ucfirst($method))) {
            return $this->callScope([$this->model, $scope], $parameters);
        }

        if (in_array(strtolower($method), $this->passthru)) {
            return $this->toBase()->{$method}(...$parameters);
        }

        $this->forwardCallTo($this->query, $method, $parameters);

        return $this;
    }

    /**
     * Apply the given scope on the current builder instance.
     */
    protected function callScope(callable $scope, array $parameters = []): static
    {
        array_unshift($parameters, $this);

        return $scope(...array_values($parameters)) ?? $this;
    }

    /**
     * Get the first record from the query.
     */
    public function first(array|string $columns = ['*']): ?Model
    {
        return $this->limit(1)->get($columns)->first();
    }

    /**
     * Get the first record from the query or throw an exception if none is found.
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(array|string $columns = ['*']): Model
    {
        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        $this->throwNotFoundException(
            $this->query->getUnescapedQuery(),
            $this->query->getDn()
        );
    }

    /**
     * Get the first record from the query or throw if none is found, or if more than one is found.
     *
     * @throws ModelNotFoundException
     * @throws MultipleObjectsFoundException
     */
    public function sole(array|string $columns = ['*']): Model
    {
        $result = $this->limit(2)->get($columns);

        if ($result->isEmpty()) {
            throw new ModelNotFoundException;
        }

        if ($result->count() > 1) {
            throw new MultipleObjectsFoundException;
        }

        return $result->first();
    }

    /**
     * Find a record by its DN or an array of DNs.
     */
    public function find(array|string $dn, array|string $columns = ['*']): Model|Collection|null
    {
        if (is_array($dn)) {
            return $this->findMany($dn, $columns);
        }

        return $this->setDn($dn)->first($columns);
    }

    /**
     * Find a record by DN or throw an exception if not found.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $dn, array|string $columns = ['*']): Model
    {
        $entry = $this->find($dn, $columns);

        if (! $entry instanceof Model) {
            $this->throwNotFoundException(
                $this->query->getUnescapedQuery(),
                $this->query->getDn()
            );
        }

        return $entry;
    }

    /**
     * Find a record by the given attribute and value or throw if not found.
     *
     * @throws ModelNotFoundException
     */
    public function findByOrFail(string $attribute, string $value, array|string $columns = ['*']): Model
    {
        $entry = $this->findBy($attribute, $value, $columns);

        if (! $entry) {
            $this->throwNotFoundException(
                $this->query->getUnescapedQuery(),
                $this->query->getDn()
            );
        }

        return $entry;
    }

    /**
     * Find a record by the given attribute and value.
     */
    public function findBy(string $attribute, string $value, array|string $columns = ['*']): ?Model
    {
        return $this->whereEquals($attribute, $value)->first($columns);
    }

    /**
     * Find multiple records by the given DN or array of DNs.
     */
    public function findMany(array|string $dns, array|string $columns = ['*']): Collection
    {
        $dns = (array) $dns;

        $collection = $this->model->newCollection();

        foreach ($dns as $dn) {
            if ($entry = $this->find($dn, $columns)) {
                $collection->push($entry);
            }
        }

        return $collection;
    }

    /**
     * Find multiple records by the given attribute and array of values.
     */
    public function findManyBy(string $attribute, array $values = [], array|string $columns = ['*']): Collection
    {
        $this->select($columns);

        if (empty($values)) {
            return $this->model->newCollection();
        }

        $this->orFilter(function (self $query) use ($attribute, $values) {
            foreach ($values as $value) {
                $query->whereEquals($attribute, $value);
            }
        });

        return $this->get($columns);
    }

    /**
     * Finds a record using ambiguous name resolution.
     */
    public function findByAnr(array|string $value, array|string $columns = ['*']): Model|Collection|null
    {
        if (is_array($value)) {
            return $this->findManyByAnr($value, $columns);
        }

        // If the model is not compatible with ANR filters,
        // we must construct an equivalent filter that
        // the current LDAP server does support.
        if (! $this->modelIsCompatibleWithAnr()) {
            return $this->prepareAnrEquivalentQuery($value)->first($columns);
        }

        return $this->findBy('anr', $value, $columns);
    }

    /**
     * Determine if the current model is compatible with ANR filters.
     */
    protected function modelIsCompatibleWithAnr(): bool
    {
        return $this->model instanceof ActiveDirectory;
    }

    /**
     * Find a record using ambiguous name resolution.
     *
     * @throws ModelNotFoundException
     */
    public function findByAnrOrFail(string $value, array|string $columns = ['*']): Model
    {
        if (! $entry = $this->findByAnr($value, $columns)) {
            $this->throwNotFoundException($this->getUnescapedQuery(), $this->query->getDn());
        }

        return $entry;
    }

    /**
     * Throws a not found exception.
     *
     * @throws ModelNotFoundException
     */
    protected function throwNotFoundException(string $query, ?string $dn = null): void
    {
        throw ModelNotFoundException::forQuery($query, $dn);
    }

    /**
     * Find multiple records using ambiguous name resolution.
     */
    public function findManyByAnr(array $values = [], array|string $columns = ['*']): Collection
    {
        $this->select($columns);

        if (! $this->modelIsCompatibleWithAnr()) {
            foreach ($values as $value) {
                $this->prepareAnrEquivalentQuery($value);
            }

            return $this->get($columns);
        }

        return $this->findManyBy('anr', $values);
    }

    /**
     * Creates an ANR equivalent query for LDAP distributions that do not support ANR.
     */
    protected function prepareAnrEquivalentQuery(string $value): static
    {
        return $this->orFilter(function (self $query) use ($value) {
            foreach ($this->model->getAnrAttributes() as $attribute) {
                $query->whereEquals($attribute, $value);
            }
        });
    }

    /**
     * Find a record by its string GUID.
     */
    public function findByGuid(string $guid, array|string $columns = ['*']): ?Model
    {
        try {
            return $this->findByGuidOrFail($guid, $columns);
        } catch (ModelNotFoundException $e) {
            return null;
        }
    }

    /**
     * Find a record by its string GUID or throw an exception.
     *
     * @throws ModelNotFoundException
     */
    public function findByGuidOrFail(string $guid, array|string $columns = ['*']): Model
    {
        if ($this->model instanceof ActiveDirectory) {
            $guid = (new Guid($guid))->getEncodedHex();
        }

        return $this->whereRaw([
            $this->model->getGuidKey() => $guid,
        ])->firstOrFail($columns);
    }

    /**
     * Get the base query builder instance.
     */
    public function toBase(): QueryBuilder
    {
        return $this->applyScopes()->getQuery();
    }

    /**
     * Get the underlying model instance.
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Set the underlying model instance.
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the underlying query builder instance.
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Apply the global query scopes.
     */
    public function applyScopes(): static
    {
        if (! $this->scopes) {
            return $this;
        }

        foreach ($this->scopes as $identifier => $scope) {
            if (isset($this->appliedScopes[$identifier])) {
                continue;
            }

            $scope instanceof Scope
                ? $scope->apply($this, $this->getModel())
                : $scope($this);

            $this->appliedScopes[$identifier] = $scope;
        }

        return $this;
    }

    /**
     * Register a new global scope.
     */
    public function withGlobalScope(string $identifier, Scope|Closure $scope): static
    {
        $this->scopes[$identifier] = $scope;

        return $this;
    }

    /**
     * Remove a registered global scope.
     */
    public function withoutGlobalScope(Scope|string $scope): static
    {
        if (! is_string($scope)) {
            $scope = get_class($scope);
        }

        unset($this->scopes[$scope]);

        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Remove all or passed registered global scopes.
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if (! is_array($scopes)) {
            $scopes = array_keys($this->scopes);
        }

        foreach ($scopes as $scope) {
            $this->withoutGlobalScope($scope);
        }

        return $this;
    }

    /**
     * Get an array of global scopes that were removed from the query.
     */
    public function removedScopes(): array
    {
        return $this->removedScopes;
    }

    /**
     * Get an array of the global scopes that were applied to the query.
     */
    public function appliedScopes(): array
    {
        return $this->appliedScopes;
    }

    /**
     * Execute the query and get the results.
     */
    public function get(array|string $columns = ['*']): Collection
    {
        $builder = $this->applyScopes();

        $models = $builder->getModels($columns);

        return $builder->getModel()->newCollection($models);
    }

    /**
     * Select the given columns to retrieve.
     */
    public function select(array|string $columns): static
    {
        // If selects are being overridden, then we need to ensure
        // the GUID key is always selected so that it may be
        // returned in the results for model hydration.
        $columns = array_values(array_unique(
            array_merge([$this->model->getGuidKey()], (array) $columns)
        ));

        $this->query->select($columns);

        return $this;
    }

    /**
     * Get the hydrated models from the query.
     */
    public function getModels(array|string $columns = ['*']): array
    {
        return $this->model->hydrate(
            $this->query->get($columns)
        )->all();
    }

    /**
     * Prepare the given field and value for usage in a where filter.
     *
     * @throws UnexpectedValueException
     */
    protected function prepareWhereValue(string $field, mixed $value = null): mixed
    {
        if (! $value instanceof DateTime) {
            return $value;
        }

        $field = $this->model->normalizeAttributeKey($field);

        if (! $this->model->isDateAttribute($field)) {
            throw new UnexpectedValueException(
                "Cannot convert field [$field] to an LDAP timestamp. You must add this field as a model date."
                .' Refer to https://ldaprecord.com/docs/core/v3/model-mutators/#date-mutators'
            );
        }

        return $this->model->fromDateTime($value, $this->model->getDates()[$field]);
    }

    /**
     * Clone the model query builder.
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;
    }
}
