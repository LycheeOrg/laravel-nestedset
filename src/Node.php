<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @template Tmodelkey
 * @template Tmodel of \Illuminate\Database\Eloquent\Model
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
	 * @return BelongsTo
	 */
	public function parent(): BelongsTo;

	/**
	 * Relation to children.
	 *
	 * @return HasMany
	 */
	public function children(): HasMany;

	/**
	 * Get query for descendants of the node.
	 *
	 * @return DescendantsRelation
	 */
	public function descendants(): DescendantsRelation;

	/**
	 * Get query for siblings of the node.
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function siblings(): QueryBuilder;

	/**
	 * Get the node siblings and the node itself.
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function siblingsAndSelf(): QueryBuilder;

	/**
	 * Get query for the node siblings and the node itself.
	 *
	 * @param string[] $columns
	 *
	 * @return EloquentCollection
	 */
	public function getSiblingsAndSelf(array $columns = ['*']): EloquentCollection;

	/**
	 * Get query for siblings after the node.
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function nextSiblings(): QueryBuilder;

	/**
	 * Get query for siblings before the node.
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function prevSiblings(): QueryBuilder;

	/**
	 * Get query for nodes after current node.
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function nextNodes(): QueryBuilder;

	/**
	 * Get query for nodes before current node in reversed order.
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function prevNodes(): QueryBuilder;

	/**
	 * Get query ancestors of the node.
	 *
	 * @return AncestorsRelation
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
	 * @param Tmodelkey $parentId
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
	 */
	public function newEloquentBuilder(QueryBuilder $query): QueryBuilder;

	/**
	 * Get a new base query that includes deleted nodes.
	 *
	 * @since 1.1
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public function newNestedSetQuery(QueryBuilder|string|null $table = null): QueryBuilder;

	/**
	 * @param ?string $table
	 *
	 * @return QueryBuilder<Tmodelkey,Tmodel>
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
	 * @return QueryBuilder<Tmodelkey,Tmodel>
	 */
	public static function scoped(array $attributes): QueryBuilder;

	/**
	 * @param array<int,Tmodel&Node> $models
	 *
	 * @return Collection<int,Tmodel&Node<Tmodelkey,Tmodel>>
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
	 * @param Tmodelkey|null $value
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
	 * @return Tmodelkey|null
	 */
	public function getParentId(): mixed;

	/**
	 * Returns node that is next to current node without constraining to siblings.
	 *
	 * This can be either a next sibling or a next sibling of the parent node.
	 *
	 * @param string[] $columns
	 *
	 * @return self
	 */
	public function getNextNode(array $columns = ['*']): Node;

	/**
	 * Returns node that is before current node without constraining to siblings.
	 *
	 * This can be either a prev sibling or parent node.
	 *
	 * @param string[] $columns
	 *
	 * @return self
	 */
	public function getPrevNode(array $columns = ['*']): Node;

	/**
	 * @param string[] $columns
	 *
	 * @return Collection
	 */
	public function getAncestors(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection|self[]
	 */
	public function getDescendants(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection|self[]
	 */
	public function getSiblings(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<self>
	 */
	public function getNextSiblings(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Collection<self>
	 */
	public function getPrevSiblings(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Node
	 */
	public function getNextSibling(array $columns = ['*']);

	/**
	 * @param string[] $columns
	 *
	 * @return Node
	 */
	public function getPrevSibling(array $columns = ['*']);

	/**
	 * @return array<int>
	 */
	public function getBounds();

	/**
	 * @param $value
	 *
	 * @return Node&Tmodel
	 */
	public function setLft(int $value): Node;

	/**
	 * @param $value
	 *
	 * @return Node&Tmodel
	 */
	public function setRgt(int $value): Node;

	/**
	 * @param Tmodelkey|null $id
	 *
	 * @return Node&Tmodel
	 */
	public function setParentId(mixed $id): Node;

	/**
	 * @param string[]|null $except
	 *
	 * @return Node
	 */
	public function replicate(?array $except = null): Node;

	/**
	 * Append and save a node.
	 *
	 * @param Node&Tmodel $node
	 *
	 * @return bool
	 */
	public function appendNode(Node $node): bool;

	/**
	 * Append a node to the new parent.
	 *
	 * @param Node&Tmodel $parent
	 *
	 * @return Node&Tmodel
	 */
	public function appendToNode(Node $parent): Node;

	/**
	 * Prepend a node to the new parent.
	 *
	 * @param Node $parent
	 *
	 * @return Node&Tmodel
	 */
	public function prependToNode(Node $parent): Node;

	/**
	 * Get whether the node is an ancestor of other node, including immediate parent.
	 *
	 * @param Node $other
	 *
	 * @return bool
	 */
	public function isAncestorOf(Node $other): bool;

	/**
	 * Get whether a node is a descendant of other node.
	 *
	 * @param Node $other
	 *
	 * @return bool
	 */
	public function isDescendantOf(Node $other): bool;

	/**
	 * Get whether a node is itself or a descendant of other node.
	 *
	 * @param Node $other
	 *
	 * @return bool
	 */
	public function isSelfOrDescendantOf(Node $other);

	/**
	 * Create a new Query.
	 *
	 * @return QueryBuilder
	 */
	public function newQuery();
}
