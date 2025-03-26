<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Kalnoy\Nestedset\Contracts\NestedSetCollection;
use Kalnoy\Nestedset\Contracts\Node;
use Kalnoy\Nestedset\Contracts\NodeQueryBuilder;

/**
 * @template Tmodel of Model
 *
 * @phpstan-type NodeModel  Node<Tmodel>&Tmodel
 *
 * @extends Relation<NodeModel,NodeModel,NestedSetCollection<Tmodel>>
 *
 * @property NodeModel $related
 * @property NodeModel $parent
 */
abstract class BaseRelation extends Relation
{
	/**
	 * @var NodeQueryBuilder<Tmodel>
	 */
	protected $query;

	/**
	 * @var NodeModel
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
	 * @param QueryBuilder<NodeModel> $builder
	 * @param NodeModel               $model
	 */
	public function __construct(QueryBuilder $builder, Model $model)
	{
		if (!NestedSet::isNode($model)) {
			throw new \InvalidArgumentException('Model must be node.');
		}
		/** @disregard P1006 */
		parent::__construct($builder, $model);
	}

	/**
	 * @param NodeModel $model
	 * @param NodeModel $related
	 *
	 * @return bool
	 */
	abstract protected function matches(Node $model, Node $related): bool;

	/**
	 * @param NodeQueryBuilder<Tmodel> $query
	 * @param NodeModel                $model
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
	 * @param EloquentBuilder<NodeModel> $query
	 * @param EloquentBuilder<NodeModel> $parentQuery
	 * @param mixed                      $columns
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parentQuery,
		$columns = ['*'],
	) {
		/** @disregard P1006 */
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
	 * @param array<int,NodeModel> $models
	 * @param string               $relation
	 *
	 * @return array<int,NodeModel>
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
	 * @return NestedSetCollection<NodeModel>
	 */
	public function getResults()
	{
		/** @disregard P1013 */
		$result = $this->query->get();

		return $result;
	}

	/**
	 * Set the constraints for an eager load of the relation.
	 *
	 * @param array<int,NodeModel> $models
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
			/** @disregard P1013 */
			$outer = $this->parent->newQuery()->setQuery($inner);

			foreach ($models as $model) {
				$this->addEagerConstraint($outer, $model);
			}
		});
	}

	/**
	 * Match the eagerly loaded results to their parents.
	 *
	 * @param array<int,NodeModel>              $models
	 * @param EloquentCollection<int,NodeModel> $results
	 * @param string                            $relation
	 *
	 * @return array<int,NodeModel>
	 */
	public function match(array $models, EloquentCollection $results, $relation)
	{
		foreach ($models as $model) {
			/** @disregard P1006 */
			$related = $this->matchForModel($model, $results);

			/** @disregard P1013 */
			$model->setRelation($relation, $related);
		}

		return $models;
	}

	/**
	 * @param NodeModel                         $model
	 * @param EloquentCollection<int,NodeModel> $results
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	protected function matchForModel(Model $model, EloquentCollection $results)
	{
		$result = $this->related->newCollection();

		foreach ($results as $related) {
			/** @disregard P1006 */
			if ($this->matches($model, $related)) {
				/** @disregard P1013 */
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
