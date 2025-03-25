<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Contracts\NestedSetCollection;
use Kalnoy\Nestedset\Exceptions\NestedSetException;

/**
 * @template Tmodel of Model
 *
 * @phpstan-type NodeModel \Kalnoy\Nestedset\Contracts\Node<Tmodel>&Model
 *
 * @extends EloquentCollection<array-key,NodeModel>
 *
 * @implements NestedSetCollection<Tmodel>
 */
final class Collection extends EloquentCollection implements NestedSetCollection
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
				/** @disregard */
				$node->setRelation('parent', null);
			}

			$children = $groupedNodes->get($node->getKey(), []);

			if (count($children) > 0) {
				foreach ($children as $child) {
					/** @disregard */
					$child->setRelation('parent', $node);
				}
			}

			/** @disregard */
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
	 * @return NestedSetCollection<Tmodel>
	 */
	public function toTree($root = false): NestedSetCollection
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
	 * @return array-key
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
	 * @return NestedSetCollection<Tmodel>
	 */
	public function toFlatTree($root = false): NestedSetCollection
	{
		/** @Var Collection<Tmodel> */
		$result = new Collection();

		if ($this->isEmpty()) {
			return $result;
		}

		/** @var NodeModel */
		$first = $this->first();
		$groupedNodes = $this->groupBy($first->getParentIdName());

		return $result->flattenTree($groupedNodes, $this->getRootNodeId($root));
	}

	/**
	 * Flatten a tree into a non recursive array.
	 *
	 * @param Collection<Tmodel> $groupedNodes
	 * @param array-key          $parentId
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	protected function flattenTree(Collection $groupedNodes, $parentId): NestedSetCollection
	{
		$nodes = $groupedNodes->get($parentId, []);

		if (count($nodes) > 0) {
			foreach ($nodes as $node) {
				$this->push($node);

				$this->flattenTree($groupedNodes, $node->getKey());
			}
		}

		return $this;
	}
}
