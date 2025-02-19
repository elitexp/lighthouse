<?php

namespace Nuwave\Lighthouse\Execution\ModelsLoader;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Nuwave\Lighthouse\Pagination\PaginationArgs;
use ReflectionClass;
use ReflectionMethod;

class PaginatedModelsLoader implements ModelsLoader
{
    /**
     * @var string
     */
    protected $relation;

    /**
     * @var \Closure
     */
    protected $decorateBuilder;

    /**
     * @var \Nuwave\Lighthouse\Pagination\PaginationArgs
     */
    protected $paginationArgs;

    public function __construct(string $relation, Closure $decorateBuilder, PaginationArgs $paginationArgs)
    {
        $this->relation = $relation;
        $this->decorateBuilder = $decorateBuilder;
        $this->paginationArgs = $paginationArgs;
    }

    public function load(EloquentCollection $parents): void
    {

        CountModelsLoader::loadCount($parents, [$this->relation => $this->decorateBuilder]);

        $relatedModels = $this->loadRelatedModels($parents);

        $relation = $this->relationInstance($parents);

        $this->hydratePivotRelation($relation, $relatedModels);
        $this->loadDefaultWith($relatedModels);
        $this->associateRelationModels($parents, $relatedModels);
        $this->convertRelationToPaginator($parents);
    }

    public function extract(Model $model)
    {
        return $model->getRelation($this->relation);
    }

    protected function loadRelatedModels(EloquentCollection $parents): EloquentCollection
    {
        $relations = $parents
            ->toBase()
            ->map(function (Model $model) use ($parents): Relation {
                $relation = $this->relationInstance($parents);

                $relation->addEagerConstraints([$model]);

                ($this->decorateBuilder)($relation, $model);

                if ($relation instanceof BelongsToMany || $relation instanceof HasManyThrough) {
                    $shouldSelect = new ReflectionMethod(get_class($relation), 'shouldSelect');
                    $shouldSelect->setAccessible(true);
                    $select = $shouldSelect->invoke($relation, ['*']);
                    $relationSelected=$relation->getBaseQuery();
                    $selectedArray=[];
                    if($relationSelected->columns==null){
                        $selectedArray=$select;
                    } else {
                        foreach($select as $column){
                            if(!in_array($column,$relationSelected->columns)){
                                $selectedArray[]=$column;
                            }
                        }
                    }
                    // @phpstan-ignore-next-line Builder mixin is not understood
                    $relation->addSelect($selectedArray);


                }

                $relation->initRelation([$model], $this->relation);
                //This is for enabling the all models fetching
                if($this->paginationArgs->first<0){
                        return $relation;
                }

                // @phpstan-ignore-next-line Builder mixin is not understood
                return $relation->forPage($this->paginationArgs->page, $this->paginationArgs->first);
            });

        // Merge all the relation queries into a single query with UNION ALL.

        /**
         * Non-null because only non-empty lists of parents are passed into this loader.
         *
         * @var \Illuminate\Database\Eloquent\Relations\Relation $firstRelation
         */
        $firstRelation = $relations->shift();

        // Use ->getQuery() to respect model scopes, such as soft deletes
        $mergedRelationQuery = $relations->reduce(
            static function (EloquentBuilder $builder, Relation $relation): EloquentBuilder {
                return $builder->unionAll(
                    // @phpstan-ignore-next-line Laravel can deal with an EloquentBuilder just fine
                    $relation->getQuery()
                );
            },
            $firstRelation->getQuery()
        );

        return $mergedRelationQuery->get();
    }

    /**
     * Use the underlying model to instantiate a relation by name.
     */
    protected function relationInstance(EloquentCollection $parents): Relation
    {
        return $this
            ->newModelQuery($parents)
            ->getRelation($this->relation);
    }

    /**
     * Return a fresh instance of a query builder for the underlying model.
     */
    protected function newModelQuery(EloquentCollection $parents): EloquentBuilder
    {
        /** @var \Illuminate\Database\Eloquent\Model $anyModelInstance */
        $anyModelInstance = $parents->first();

        /** @var \Illuminate\Database\Eloquent\Builder $newModelQuery */
        $newModelQuery = $anyModelInstance->newModelQuery();

        return $newModelQuery;
    }

    /**
     * Ensure the pivot relation is hydrated too, if it exists.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<\Illuminate\Database\Eloquent\Model>  $relatedModels
     */
    protected function hydratePivotRelation(Relation $relation, EloquentCollection $relatedModels): void
    {
        /**
         * @see BelongsToMany::hydratePivotRelation()
         */
        if ($relation instanceof BelongsToMany) {
            $hydrationMethod = new ReflectionMethod($relation, 'hydratePivotRelation');
            $hydrationMethod->setAccessible(true);
            $hydrationMethod->invoke($relation, $relatedModels->all());
        }
    }

    /**
     * Ensure the models default relations are loaded.
     *
     * This is necessary because we load models in a non-standard way in @see loadRelatedModels()
     *
     * @param  \Illuminate\Database\Eloquent\Collection<\Illuminate\Database\Eloquent\Model>  $models
     */
    protected function loadDefaultWith(EloquentCollection $models): void
    {
        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $models->first();
        if (null === $model) {
            return;
        }

        $reflection = new ReflectionClass($model);
        $withProperty = $reflection->getProperty('with');
        $withProperty->setAccessible(true);

        $unloadedWiths = array_filter(
            (array) $withProperty->getValue($model),
            static function (string $relation) use ($model): bool {
                return ! $model->relationLoaded($relation);
            }
        );

        if (count($unloadedWiths) > 0) {
            $models->load($unloadedWiths);
        }
    }

    /**
     * Associate the collection of all fetched relationModels back with their parents.
     */
    protected function associateRelationModels(EloquentCollection $parents, EloquentCollection $relatedModels): void
    {
        $this
            ->relationInstance($parents)
            ->match(
                $parents->all(),
                $relatedModels,
                $this->relation
            );
    }

    protected function convertRelationToPaginator(EloquentCollection $parents): void
    {
        foreach ($parents as $model) {
            $model->setRelation(
                $this->relation,
                new LengthAwarePaginator(
                    $model->getRelation($this->relation),
                    CountModelsLoader::extractCount($model, $this->relation),
                    $this->paginationArgs->first,
                    $this->paginationArgs->page
                )
            );
        }
    }
}
