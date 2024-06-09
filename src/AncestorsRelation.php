<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;

/**
 * @disregard P1037
 */
class AncestorsRelation extends BaseRelation
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

		$this->query->whereAncestorOf($this->parent)
			->applyNestedSetScope();
	}

	/**
	 * @param Model&Node $model
	 * @param Node       $related
	 *
	 * @return bool
	 */
	protected function matches(Model $model, $related): bool
	{
		return $related->isAncestorOf($model);
	}

	/**
	 * @param QueryBuilder $query
	 * @param Model        $model
	 *
	 * @return void
	 */
	protected function addEagerConstraint($query, $model)
	{
		$query->orWhereAncestorOf($model);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
	{
		$key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

		return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and $table.$key <> $hash.$key";
	}
}
