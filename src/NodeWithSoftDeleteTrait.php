<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model&\Kalnoy\Nestedset\NodeWithSoftDelete
 */
trait NodeWithSoftDeleteTrait
{
	use SoftDeletes {
		restore as private restoreOrig;
	}
	/** @phpstan-use NodeTrait<TModelClass> */
	use NodeTrait {
		deleteDescendants as private deleteDescendantsOrig;
	}

	/**
	 * Update the tree when the node is removed physically.
	 */
	protected function deleteDescendants(): void
	{
		if ($this->forceDeleting) {
			$this->deleteDescendantsOrig();
		} else {
			$this->descendants()->delete();
		}
	}

	/**
	 * Restore the descendants.
	 *
	 * @param \DateTimeInterface|int|float $deletedAt
	 */
	protected function restoreDescendants(\DateTimeInterface|int|float $deletedAt): void
	{
		/** @var Collection<TModelClass> $descendants */
		$descendants = $this->descendants()
			->where($this->getDeletedAtColumn(), '>=', $deletedAt)
			->get();
		/**
		 * @var \Illuminate\Database\Eloquent\Model&\Kalnoy\Nestedset\NodeWithSoftDelete $descendant
		 * @phpstan-var TModelClass $descendant
		 */
		foreach ($descendants as $descendant) {
			$descendant->restore();
		}
	}

	/**
	 * Restore a soft-deleted model instance.
	 *
	 * @return bool|null
	 */
	public function restore()
	{
		/** @var \DateTimeInterface|int|float $deletedAt */
		$deletedAt = $this->{$this->getDeletedAtColumn()};
		$result = $this->restoreOrig();

		// TODO: Fix this.
		// This causes unnecessary many restore operations on deep subtrees.
		// The descendant relation returns all descendants not only those of
		// the next level.
		// And the descendants also call `restore` on their descendants,
		// hence the deeper level of nodes are restored several times.
		$this->restoreDescendants($deletedAt);

		return $result;
	}

	/**
	 * Get a new base query that includes deleted nodes.
	 *
	 * @param string|null $table
	 * @return QueryBuilder<TModelClass>
	 * @since 1.1
	 *
	 */
	public function newNestedSetQuery(?string $table = null): QueryBuilder
	{
		return $this->applyNestedSetScope($this->withTrashed(), $table);
	}
}
