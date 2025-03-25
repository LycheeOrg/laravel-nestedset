<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Contracts\Node;
use Kalnoy\Nestedset\Contracts\NodeQueryBuilder;

/**
 * @template Tmodel of Model
 *
 * @phpstan-type NodeModel \Kalnoy\Nestedset\Contracts\Node<Tmodel>&Tmodel
 *
 * @extends BaseRelation<Tmodel>
 */
final class AncestorsRelation extends BaseRelation
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
	 * @param NodeModel $model
	 * @param NodeModel $related
	 *
	 * @return bool
	 */
	protected function matches(Node $model, Node $related): bool
	{
		return $related->isAncestorOf($model);
	}

	/**
	 * @param NodeQueryBuilder<Tmodel> $query
	 * @param NodeModel            $model
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
		/** @disregard P1013 */
		$key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

		return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and $table.$key <> $hash.$key";
	}
}
