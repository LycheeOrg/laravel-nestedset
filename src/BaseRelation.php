<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;

/**
 * @template Tmodelkey
 * @template Tmodel of Model&Node<Tmodelkey,Model>
 *
 * @extends Relation<Tmodel>
 *
 * @property Tmodel $related
 * @property Tmodel $parent
 *
 * @method Tmodel getParent()
 */
abstract class BaseRelation extends Relation
{
	/**
	 * @var QueryBuilder
	 */
	protected $query;

	/**
	 * @var Node&Model
	 */
	protected $parent;

	/**
	 * The count of self joins.
	 *
	 * @var int
	 */
	protected static $selfJoinCount = 0;

	/**
	 * AncestorsRelation constructor.
	 *
	 * @param QueryBuilder $builder
	 * @param Model        $model
	 */
	public function __construct(QueryBuilder $builder, Model $model)
	{
		if (!NestedSet::isNode($model)) {
			throw new \InvalidArgumentException('Model must be node.');
		}

		parent::__construct($builder, $model);
	}

	/**
	 * @param Model&Node $model
	 * @param Node       $related
	 *
	 * @return bool
	 */
	abstract protected function matches(Model&Node $model, Node $related): bool;

	/**
	 * @param QueryBuilder $query
	 * @param Model        $model
	 *
	 * @return void
	 */
	abstract protected function addEagerConstraint($query, $model);

	/**
	 * @param string $hash
	 * @param string $table
	 * @param string $lft
	 * @param string $rgt
	 *
	 * @return string
	 */
	abstract protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string;

	/**
	 * @param EloquentBuilder $query
	 * @param EloquentBuilder $parent
	 * @param mixed           $columns
	 *
	 * @return EloquentBuilder
	 */
	public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parent,
		$columns = ['*']
	) {
		$query = $this->getParent()->replicate()->newScopedQuery()->select($columns);

		$table = $query->getModel()->getTable();

		$query->from($table . ' as ' . $hash = $this->getRelationCountHash());

		$query->getModel()->setTable($hash);

		$grammar = $query->getQuery()->getGrammar();

		$condition = $this->relationExistenceCondition(
			$grammar->wrapTable($hash),
			$grammar->wrapTable($table),
			$grammar->wrap($this->parent->getLftName()),
			$grammar->wrap($this->parent->getRgtName()));

		return $query->whereRaw($condition);
	}

	/**
	 * Initialize the relation on a set of models.
	 *
	 * @param array<int,Tmodel> $models
	 * @param string            $relation
	 *
	 * @return array<int,Tmodel>
	 */
	public function initRelation(array $models, $relation)
	{
		return $models;
	}

	/**
	 * Get a relationship join table hash.
	 *
	 * @param bool $incrementJoinCount
	 *
	 * @return string
	 */
	public function getRelationCountHash($incrementJoinCount = true)
	{
		return 'nested_set_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
	}

	/**
	 * Get the results of the relationship.
	 *
	 * @return mixed
	 */
	public function getResults()
	{
		return $this->query->get();
	}

	/**
	 * Set the constraints for an eager load of the relation.
	 *
	 * @param array<int,Tmodel> $models
	 *
	 * @return void
	 */
	public function addEagerConstraints(array $models)
	{
		// The first model in the array is always the parent, so add the scope constraints based on that model.
		// @link https://github.com/laravel/framework/pull/25240
		// @link https://github.com/lazychaser/laravel-nestedset/issues/351
		optional(reset($models))->applyNestedSetScope($this->query);

		$this->query->whereNested(function (Builder $inner) use ($models) {
			// We will use this query in order to apply constraints to the
			// base query builder
			$outer = $this->parent->newQuery()->setQuery($inner);

			foreach ($models as $model) {
				$this->addEagerConstraint($outer, $model);
			}
		});
	}

	/**
	 * Match the eagerly loaded results to their parents.
	 *
	 * @param array<int,Tmodel>  $models
	 * @param EloquentCollection $results
	 * @param string             $relation
	 *
	 * @return array<int,Tmodel>
	 */
	public function match(array $models, EloquentCollection $results, $relation)
	{
		foreach ($models as $model) {
			$related = $this->matchForModel($model, $results);

			$model->setRelation($relation, $related);
		}

		return $models;
	}

	/**
	 * @param Tmodel             $model
	 * @param EloquentCollection $results
	 *
	 * @return Collection
	 */
	protected function matchForModel(Model $model, EloquentCollection $results)
	{
		/** @var Collection<int,Tmodel> */
		$result = $this->related->newCollection();

		/** @var Tmodel $related */
		foreach ($results as $related) {
			if ($this->matches($model, $related)) {
				$result->push($related);
			}
		}

		return $result;
	}

	/**
	 * Get the plain foreign key.
	 *
	 * @return mixed
	 */
	public function getForeignKeyName()
	{
		// Return a stub value for relation
		// resolvers which need this function.
		return NestedSet::PARENT_ID;
	}
}
