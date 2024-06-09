<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TKey of array-key
 * @template Tmodelkey
 * @template Tmodel of Model
 *
 * @phpstan-type NodeModel Node<Tmodelkey,Tmodel>&Tmodel
 *
 * @extends EloquentCollection<TKey,NodeModel>
 */
final class Collection extends EloquentCollection
{
	/**
	 * Fill `parent` and `children` relationships for every node in the collection.
	 *
	 * This will overwrite any previously set relations.
	 *
	 * @return $this
	 */
	public function linkNodes()
	{
		if ($this->isEmpty()) {
			return $this;
		}

		/** @var NodeModel */
		$first = $this->first();
		$groupedNodes = $this->groupBy($first->getParentIdName());

		/** @var NodeModel $node */
		foreach ($this->items as $node) {
			if ($node->getParentId() === null) {
				$node->setRelation('parent', null);
			}

			/** @var array<int,NodeModel> */
			$children = $groupedNodes->get($node->getKey(), []);

			foreach ($children as $child) {
				$child->setRelation('parent', $node);
			}

			$node->setRelation('children', EloquentCollection::make($children));
		}

		return $this;
	}

	/**
	 * Build a tree from a list of nodes. Each item will have set children relation.
	 *
	 * To successfully build tree "id", "_lft" and "parent_id" keys must present.
	 *
	 * If `$root` is provided, the tree will contain only descendants of that node.
	 *
	 * @param mixed $root
	 *
	 * @return Collection<TKey,Tmodelkey,Tmodel>
	 */
	public function toTree($root = false)
	{
		if ($this->isEmpty()) {
			return new static();
		}

		$this->linkNodes();

		$items = [];

		$root = $this->getRootNodeId($root);

		/** @var NodeModel $node */
		foreach ($this->items as $node) {
			if ($node->getParentId() === $root) {
				$items[] = $node;
			}
		}

		return new static($items);
	}

	/**
	 * @param mixed $root
	 *
	 * @return Tmodelkey
	 */
	protected function getRootNodeId($root = false)
	{
		if (NestedSet::isNode($root)) {
			return $root->getKey();
		}

		if ($root !== false) {
			return $root;
		}

		// If root node is not specified we take parent id of node with
		// least lft value as root node id.
		$leastValue = null;

		/** @var NodeModel $node */
		foreach ($this->items as $node) {
			if ($leastValue === null || $node->getLft() < $leastValue) {
				$leastValue = $node->getLft();
				$root = $node->getParentId();
			}
		}

		if ($root === null || $root === false) {
			throw new NestedSetException('root is null or false.');
		}

		return $root;
	}

	/**
	 * Build a list of nodes that retain the order that they were pulled from
	 * the database.
	 *
	 * @param bool $root
	 *
	 * @return Collection<TKey,Tmodelkey,Tmodel>
	 */
	public function toFlatTree($root = false): Collection
	{
		/** @Var Collection<TKey,Tmodelkey,Tmodel> */
		$result = new Collection();

		if ($this->isEmpty()) {
			return $result; /** @phpstan-ignore-line */
		}

		/** @var NodeModel */
		$first = $this->first();
		/** @var Collection<TKey,Tmodelkey,NodeModel> */
		$groupedNodes = $this->groupBy($first->getParentIdName());

		return $result->flattenTree($groupedNodes, $this->getRootNodeId($root)); /** @phpstan-ignore-line */
	}

	/**
	 * Flatten a tree into a non recursive array.
	 *
	 * @param Collection<TKey,Tmodelkey,Tmodel> $groupedNodes
	 * @param Tmodelkey                    $parentId
	 *
	 * @return Collection<TKey,Tmodelkey,Tmodel>
	 */
	protected function flattenTree(Collection $groupedNodes, $parentId): Collection
	{
		/** @var array<int,NodeModel> */
		$nodes = $groupedNodes->get($parentId, []);
		foreach ($nodes as $node) {
			$this->push($node);

			$this->flattenTree($groupedNodes, $node->getKey());
		}

		return $this;
	}
}
