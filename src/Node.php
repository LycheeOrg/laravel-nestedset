<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;

/**
 * Accompanies {@link \Kalnoy\Nestedset\NodeTrait}.
 *
 * This interface declares all public methods of a node which are implemented
 * by {@link \Kalnoy\Nestedset\NodeTrait}.
 *
 * Every model which represents a node in a nested set, must realize this
 * interface.
 * This interface is mandatory such that
 * {@link \Kalnoy\Nestedset\NestedSet::isNode()} recognizes an object as a
 * node.
 *
 * @template Tmodel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type NodeModel Node<Tmodel>&Tmodel
 */
interface Node
{
	/**
	 * Refresh node's crucial attributes.
	 */
	public function refreshNode(): void;

	/**
	 * Relation to the parent.
	 *
	 * @return BelongsTo<NodeModel,NodeModel>
	 */
	public function parent(): BelongsTo;

	/**
	 * Relation to children.
	 *
	 * @return HasMany<NodeModel,NodeModel>
	 */
	public function children(): HasMany;

	/**
	 * Get query for descendants of the node.
	 *
	 * @return DescendantsRelation<Tmodel>
	 */
	public function descendants(): DescendantsRelation;

	/**
	 * Get query for siblings of the node.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function siblings(): QueryBuilder;

	/**
	 * Get the node siblings and the node itself.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function siblingsAndSelf(): QueryBuilder;

	/**
	 * Get query for the node siblings and the node itself.
	 *
	 * @param string[] $columns
	 *
	 * @return EloquentCollection<int,NodeModel>
	 */
	public function getSiblingsAndSelf(array $columns = ['*']): EloquentCollection;

	/**
	 * Get query for siblings after the node.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function nextSiblings(): QueryBuilder;

	/**
	 * Get query for siblings before the node.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function prevSiblings(): QueryBuilder;

	/**
	 * Get query for nodes after current node.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function nextNodes(): QueryBuilder;

	/**
	 * Get query for nodes before current node in reversed order.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function prevNodes(): QueryBuilder;

	/**
	 * Get query ancestors of the node.
	 *
	 * @return AncestorsRelation<Tmodel>
	 */
	public function ancestors(): AncestorsRelation;

	/**
	 * Make this node a root node.
	 *
	 * @return $this
	 */
	public function makeRoot(): Node;

	/**
	 * Save node as root.
	 *
	 * @return bool
	 */
	public function saveAsRoot(): bool;

	/**
	 * @param int       $lft
	 * @param int       $rgt
	 * @param array-key $parentId
	 *
	 * @return $this
	 */
	public function rawNode(int $lft, int $rgt, mixed $parentId): Node;

	/**
	 * Move node up given amount of positions.
	 *
	 * @param int $amount
	 *
	 * @return bool
	 */
	public function up(int $amount = 1): bool;

	/**
	 * Move node down given amount of positions.
	 *
	 * @param int $amount
	 *
	 * @return bool
	 */
	public function down(int $amount = 1): bool;

	/**
	 * @since 2.0
	 *
	 * @param BaseQueryBuilder|EloquentBuilder<Tmodel>|QueryBuilder<Tmodel> $query
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function newEloquentBuilder(BaseQueryBuilder|EloquentBuilder|QueryBuilder $query): QueryBuilder;

	/**
	 * Get a new base query that includes deleted nodes.
	 *
	 * @since 1.1
	 *
	 * @param (QueryBuilder<Tmodel>)|string|null $table
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function newNestedSetQuery(QueryBuilder|string|null $table = null): QueryBuilder;

	/**
	 * @param ?string $table
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function newScopedQuery($table = null);

	/**
	 * @param mixed   $query
	 * @param ?string $table
	 *
	 * @return mixed
	 */
	public function applyNestedSetScope($query, $table = null);

	/**
	 * @param string[] $attributes
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public static function scoped(array $attributes): QueryBuilder;

	/**
	 * @param array<int,NodeModel> $models
	 *
	 * @return Collection<Tmodel>
	 */
	public function newCollection(array $models = []): Collection;

	/**
	 * Get node height (rgt - lft + 1).
	 */
	public function getNodeHeight(): int;

	/**
	 * Get number of descendant nodes.
	 */
	public function getDescendantCount(): int;

	/**
	 * Set the value of model's parent id key.
	 *
	 * Behind the scenes node is appended to found parent node.
	 *
	 * @param array-key|null $value
	 *
	 * @throws \Exception If parent node doesn't exists
	 */
	public function setParentIdAttribute(mixed $value): void;

	/**
	 * Get whether node is root.
	 */
	public function isRoot(): bool;

	public function isLeaf(): bool;

	/**
	 * Get the lft key name.
	 */
	public function getLftName(): string;

	/**
	 * Get the rgt key name.
	 */
	public function getRgtName(): string;

	/**
	 * Get the parent id key name.
	 */
	public function getParentIdName(): string;

	/**
	 * Get the value of the model's lft key.
	 */
	public function getLft(): int|null;

	/**
	 * Get the value of the model's rgt key.
	 */
	public function getRgt(): int|null;

	/**
	 * Get the value of the model's parent id key.
	 *
	 * @return array-key|null
	 */
	public function getParentId(): mixed;

	/**
	 * Returns node that is next to current node without constraining to siblings.
	 *
	 * This can be either a next sibling or a next sibling of the parent node.
	 *
	 * @param string[] $columns
	 *
	 * @return NodeModel
	 */
	public function getNextNode(array $columns = ['*']): Node;

	/**
	 * Returns node that is before current node without constraining to siblings.
	 *
	 * This can be either a prev sibling or parent node.
	 *
	 * @param string[] $columns
	 *
	 * @return NodeModel
	 */
	public function getPrevNode(array $columns = ['*']): Node;

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<Tmodel>
	 */
	public function getAncestors(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<Tmodel>
	 */
	public function getDescendants(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<Tmodel>
	 */
	public function getSiblings(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<Tmodel>
	 */
	public function getNextSiblings(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<Tmodel>
	 */
	public function getPrevSiblings(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return NodeModel
	 */
	public function getNextSibling(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return NodeModel
	 */
	public function getPrevSibling(array $columns = ['*']);

	/**
	 * @return array<int>
	 */
	public function getBounds();

	/**
	 * @param $value
	 *
	 * @return NodeModel
	 */
	public function setLft(int $value): Node;

	/**
	 * @param $value
	 *
	 * @return NodeModel
	 */
	public function setRgt(int $value): Node;

	/**
	 * @param array-key|null $id
	 *
	 * @return NodeModel
	 */
	public function setParentId(mixed $id): Node;

	/**
	 * @param string[]|null $except
	 *
	 * @return NodeModel
	 */
	public function replicate(?array $except = null): Node;

	/**
	 * Append and save a node.
	 *
	 * @param NodeModel $node
	 *
	 * @return bool
	 */
	public function appendNode(Node $node): bool;

	/**
	 * Append a node to the new parent.
	 *
	 * @param NodeModel $parent
	 *
	 * @return NodeModel
	 */
	public function appendToNode(Node $parent): Node;

	/**
	 * Prepend a node to the new parent.
	 *
	 * @param NodeModel $parent
	 *
	 * @return NodeModel
	 */
	public function prependToNode(Node $parent): Node;

	/**
	 * Get whether the node is an ancestor of other node, including immediate parent.
	 *
	 * @param NodeModel $other
	 *
	 * @return bool
	 */
	public function isAncestorOf(Node $other): bool;

	/**
	 * Get whether a node is a descendant of other node.
	 *
	 * @param NodeModel $other
	 *
	 * @return bool
	 */
	public function isDescendantOf(Node $other): bool;

	/**
	 * Get whether a node is itself or a descendant of other node.
	 *
	 * @param NodeModel $other
	 *
	 * @return bool
	 */
	public function isSelfOrDescendantOf(Node $other);

	/**
	 * Create a new Query.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function newQuery();
}
