<?php

namespace Kalnoy\Nestedset\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;

/**
 * @template Tmodel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type NodeModel Node<Tmodel>
 *
 * @require-extends Illuminate\Database\Eloquent\Builder<NodeModel>
 *
 * @method NodeQueryBuilder<Tmodel>                    select(string[] $columns)
 * @method Tmodel                                      getModel()
 * @method NodeQueryBuilder<Tmodel>                    from(string $table)
 * @method NodeQueryBuilder<Tmodel>                    getQuery()
 * @method NodeQueryBuilder<Tmodel>                    whereRaw(string $sql, string[] $bindings = [], string $boolean = 'and')
 * @method \Illuminate\Database\Query\Grammars\Grammar getGrammar()
 * @method NodeQueryBuilder<Tmodel>                    whereNested(\Closure|string $callback, string $boolean = 'and')
 * @method NestedSetCollection<Tmodel>                 get(string[] $columns = ['*'])
 * @method int                                         max(string $column)
 * @method NodeQueryBuilder<Tmodel>                    where(string|string[]|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method NodeModel|null                              first(string[]|string $columns = ['*'])
 * @method NodeModel                                   findOrFail(int|string $id)
 * @method NodeQueryBuilder<Tmodel>                    skip(int $value)
 * @method NodeQueryBuilder<Tmodel>                    take(int $value)
 * @method NodeQueryBuilder<Tmodel>                    orderBy(string $column, string $direction = 'asc')
 * @method NodeQueryBuilder<Tmodel>                    when(bool $value, \Closure $callback)
 * @method BaseQueryBuilder                            toBase()
 * @method NodeQueryBuilder<Tmodel>                    whereIn(string $column, array<int,string> $values, string $boolean = 'and', string $not = false)
 * @method NodeQueryBuilder<Tmodel>                    whereRaw(string $sql, string[] $bindings = [], string $boolean = 'and')
 * @method int delete(null|mixed $id = null)
 */
interface NodeQueryBuilder extends Builder
{
	/**
	 * Get node's `lft` and `rgt` values.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param bool      $required
	 *
	 * @return array<int,int>
	 */
	public function getNodeData(mixed $id, $required = false): array;

	/**
	 * Get plain node data.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param bool      $required
	 *
	 * @return array<int,int>
	 */
	public function getPlainNodeData(mixed $id, $required = false): array;

	/**
	 * Scope limits query to select just root node.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereIsRoot(): NodeQueryBuilder;

	/**
	 * Limit results to ancestors of specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param bool      $andSelf
	 * @param string    $boolean
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereAncestorOf(mixed $id, bool $andSelf = false, string $boolean = 'and'): NodeQueryBuilder;

	/**
	 * @param NodeModel $id
	 * @param bool      $andSelf
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function orWhereAncestorOf(mixed $id, $andSelf = false): NodeQueryBuilder;

	/**
	 * @param NodeModel $id
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereAncestorOrSelf(mixed $id): NodeQueryBuilder;

	/**
	 * Get ancestors of specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string[]  $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function ancestorsOf(mixed $id, array $columns = ['*']): NestedSetCollection;

	/**
	 * @param NodeModel $id
	 * @param string[]  $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function ancestorsAndSelf(mixed $id, array $columns = ['*']): NestedSetCollection;

	/**
	 * Add node selection statement between specified range.
	 *
	 * @since 2.0
	 *
	 * @param int[]  $values
	 * @param string $boolean
	 * @param bool   $not
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereNodeBetween(array $values, $boolean = 'and', $not = false): NodeQueryBuilder;

	/**
	 * Add node selection statement between specified range joined with `or` operator.
	 *
	 * @since 2.0
	 *
	 * @param int[] $values
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function orWhereNodeBetween(array $values): NodeQueryBuilder;

	/**
	 * Add constraint statement to descendants of specified node.
	 *
	 * @since 2.0
	 *
	 * @param ?NodeModel $id
	 * @param string     $boolean
	 * @param bool       $not
	 * @param bool       $andSelf
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereDescendantOf(mixed $id, $boolean = 'and', $not = false, $andSelf = false): NodeQueryBuilder;

	/**
	 * @param NodeModel $id
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereNotDescendantOf(mixed $id): NodeQueryBuilder;

	/**
	 * @param NodeModel $id
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function orWhereDescendantOf(mixed $id): NodeQueryBuilder;

	/**
	 * @param NodeModel $id
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function orWhereNotDescendantOf(mixed $id): NodeQueryBuilder;

	/**
	 * @param NodeModel $id
	 * @param string    $boolean
	 * @param bool      $not
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereDescendantOrSelf(mixed $id, string $boolean = 'and', bool $not = false): NodeQueryBuilder;

	/**
	 * Get descendants of specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string[]  $columns
	 * @param bool      $andSelf
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function descendantsOf(mixed $id, array $columns = ['*'], bool $andSelf = false): NestedSetCollection;

	/**
	 * @param NodeModel $id
	 * @param string[]  $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function descendantsAndSelf($id, array $columns = ['*']): NestedSetCollection;

	/**
	 * Constraint nodes to those that are after specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string    $boolean
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereIsAfter($id, $boolean = 'and'): NodeQueryBuilder;

	/**
	 * Constraint nodes to those that are before specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string    $boolean
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereIsBefore($id, $boolean = 'and'): NodeQueryBuilder;

	/**
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function whereIsLeaf(): NodeQueryBuilder;

	/**
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function leaves(array $columns = ['*']): NestedSetCollection;

	/**
	 * Include depth level into the result.
	 *
	 * @param string $as
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function withDepth($as = 'depth'): NodeQueryBuilder;

	/**
	 * Exclude root node from the result.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function withoutRoot(): NodeQueryBuilder;

	/**
	 * Equivalent of `withoutRoot`.
	 *
	 * @since 2.0
	 * @deprecated since v4.1
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function hasParent(): NodeQueryBuilder;

	/**
	 * Get only nodes that have children.
	 *
	 * @since 2.0
	 * @deprecated since v4.1
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function hasChildren(): NodeQueryBuilder;

	/**
	 * Order by node position.
	 *
	 * @param string $dir
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function defaultOrder($dir = 'asc'): NodeQueryBuilder;

	/**
	 * Order by reversed node position.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function reversed(): NodeQueryBuilder;

	/**
	 * Move a node to the new position.
	 *
	 * @param mixed $key
	 * @param int   $position
	 *
	 * @return int
	 */
	public function moveNode($key, $position): int;

	/**
	 * Make or remove gap in the tree. Negative height will remove gap.
	 *
	 * @since 2.0
	 *
	 * @param int $cut
	 * @param int $height
	 *
	 * @return int
	 */
	public function makeGap($cut, $height): int;

	/**
	 * Get statistics of errors of the tree.
	 *
	 * @since 2.0
	 *
	 * @return array{oddness:int,duplicates:int,wrong_parent:int,missing_parent:int}
	 */
	public function countErrors(): array;

	/**
	 * Get the number of total errors of the tree.
	 *
	 * @since 2.0
	 *
	 * @return int
	 */
	public function getTotalErrors(): int;

	/**
	 * Get whether the tree is broken.
	 *
	 * @since 2.0
	 *
	 * @return bool
	 */
	public function isBroken(): bool;

	/**
	 * Fixes the tree based on parentage info.
	 *
	 * Nodes with invalid parent are saved as roots.
	 *
	 * @param ?NodeModel $root
	 *
	 * @return int The number of changed nodes
	 */
	public function fixTree($root = null): int;

	/**
	 * @param NodeModel $root
	 *
	 * @return int
	 */
	public function fixSubtree($root): int;

	/**
	 * Rebuild the tree based on raw data.
	 *
	 * If item data does not contain primary key, new node will be created.
	 *
	 * @param array<array-key,NodeModel[]>[] $data
	 * @param bool                           $delete Whether to delete nodes that exists but not in the data array
	 * @param ?NodeModel                     $root
	 *
	 * @return int
	 */
	public function rebuildTree(array $data, $delete = false, $root = null): int;

	/**
	 * @param null                  $root
	 * @param array<string,mixed>[] $data
	 * @param bool                  $delete
	 *
	 * @return int
	 */
	public function rebuildSubtree($root, array $data, $delete = false): int;

	/**
	 * @param string|null $table
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function applyNestedSetScope($table = null): NodeQueryBuilder;

	/**
	 * Get the root node.
	 *
	 * @param string[] $columns
	 *
	 * @return NodeModel|null
	 */
	public function root(array $columns = ['*']): Node|null;
}