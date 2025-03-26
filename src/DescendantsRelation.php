<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Contracts\Node;

/**
 * @template Tmodel of Model
 *
 * @phpstan-type NodeModel \Kalnoy\Nestedset\Contracts\Node<Tmodel>
 *
 * @disregard P1037
 *
 * @extends BaseRelation<Tmodel>
 */
final class DescendantsRelation extends BaseRelation
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
	 * @param QueryBuilder<Tmodel> $query
	 * @param NodeModel            $model
	 */
	protected function addEagerConstraint($query, $model)
	{
		$query->orWhereDescendantOf($model);
	}

	/**
	 * @param NodeModel $model
	 * @param NodeModel $related
	 *
	 * @return bool
	 */
	protected function matches(Node $model, Node $related): bool
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