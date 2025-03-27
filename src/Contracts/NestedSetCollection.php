<?php

namespace Kalnoy\Nestedset\Contracts;

/**
 * @template-covariant Tmodel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type NodeModel Node<Tmodel>&Tmodel
 *
 * @require-extends \Illuminate\Database\Eloquent\Collection
 *
 * @method NestedSetCollection<NodeModel> groupBy(string $column)
 * @method array<int,NodeModel>           all()
 * @method \Illuminate\Support\Collection<array-key,NodeModel> toBase()
 */
interface NestedSetCollection
{
	/**
	 * Fill `parent` and `children` relationships for every node in the collection.
	 *
	 * This will overwrite any previously set relations.
	 *
	 * @return NestedSetCollection<Tmodel>
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
	 * @return NestedSetCollection<Tmodel>
	 */
	public function toTree($root = false): NestedSetCollection;

	/**
	 * Build a list of nodes that retain the order that they were pulled from
	 * the database.
	 *
	 * @param bool $root
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function toFlatTree($root = false): NestedSetCollection;
}
