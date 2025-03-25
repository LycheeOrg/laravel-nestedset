<?php


namespace Kalnoy\Nestedset\Contracts;

/**
 * @template Tmodel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type NodeModel Node<Tmodel>
 *
 * @require-extends \Illuminate\Database\Eloquent\Collection
 */
interface NestedSetCollection
{
	/**
	 * Fill `parent` and `children` relationships for every node in the collection.
	 *
	 * This will overwrite any previously set relations.
	 *
	 * @return $this
	 */
	public function linkNodes();


	/**
	 * Build a tree from a list of nodes. Each item will have set children relation.
	 *
	 * To successfully build tree "id", "_lft" and "parent_id" keys must present.
	 *
	 * If `$root` is provided, the tree will contain only descendants of that node.
	 *
	 * @param mixed $root
	 *
	 * @return Collection<NodeModel>
	 */
	public function toTree($root = false): NestedSetCollection;

	/**
	 * Build a list of nodes that retain the order that they were pulled from
	 * the database.
	 *
	 * @param bool $root
	 *
	 * @return Collection<NodeModel>
	 */
	public function toFlatTree($root = false): NestedSetCollection;
}
