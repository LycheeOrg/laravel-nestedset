<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;

/**
 * @template Tmodelkey
 * @template Tmodel of Model
 * 
 * @phpstan-type NodeModel Node<Tmodelkey,Tmodel>&Tmodel
 * 
 * @disregard P1037
 * 
 * @extends BaseRelation<Tmodelkey,Tmodel>
 */
class DescendantsRelation extends BaseRelation
{
	/**
	 * Set the base constraints on the relation query.
	 *
	 * @return void
	 */
	public function addConstraints()
	{
		if (!static::$constraints) {
			return;
		}

		$this->query->whereDescendantOf($this->parent)
		->applyNestedSetScope();
	}

	/**
	 * @param QueryBuilder $query
	 * @param Model        $model
	 */
	protected function addEagerConstraint($query, $model)
	{
		$query->orWhereDescendantOf($model);
	}

	/**
	 * @param Model&Node $model
	 * @param Node       $related
	 *
	 * @return bool
	 */
	protected function matches(Model $model, $related): bool
	{
		return $related->isDescendantOf($model);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
	{
		return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
	}
}