<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as BaseBuilder;
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
 * @template TModelClass of \Illuminate\Database\Eloquent\Model&\Kalnoy\Nestedset\Node
 *
 * @property int $_lft
 * @property int $_rgt
 * @property int|string|null $parent_id
 * @property Node $parent
 * @phpstan-property TModelClass $parent
 * @property Collection<TModelClass> $children
 * @property Collection<TModelClass> $descendants
 * @property Collection<TModelClass> $ancestors
 */
interface Node
{
	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::getKey()}.
	 *
	 * @return mixed
	 */
	public function getKey();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::save()}.
	 *
	 * @param  array{touch?: bool}  $options
	 * @return bool
	 */
	public function save(array $options = []);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::getKeyName()}.
	 *
	 * @return string
	 */
	public function getKeyName();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::getTable()}.
	 *
	 * @return string
	 */
	public function getTable();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::newQuery()}.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function newQuery();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasRelationships::setRelation()}.
	 *
	 * @param  string  $relation
	 * @param  mixed  $value
	 * @return TModelClass&$this
	 */
	public function setRelation($relation, $value);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasRelationships::belongsTo()}.
	 *
	 * @param  string  $related
	 * @param  string|null  $foreignKey
	 * @param  string|null  $ownerKey
	 * @param  string|null  $relation
	 * @return BelongsTo<TModelClass, TModelClass>
	 */
	public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasRelationships::hasMany()}.
	 *
	 * @param string $related
	 * @param string|null $foreignKey
	 * @param string|null  $localKey
	 * @return HasMany<TModelClass>
	 */
	public function hasMany($related, $foreignKey = null, $localKey = null);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::getAttributeValue()}.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function getAttributeValue($key);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::isDirty()}.
	 *
	 * @param  array|string|null $attributes
	 * @return bool
	 */
	public function isDirty($attributes = null);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::getAttribute()}.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function getAttribute($key);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::setRawAttributes()}.
	 *
	 * @param  array  $attributes
	 * @param  bool  $sync
	 * @return TModelClass&$this
	 */
	public function setRawAttributes(array $attributes, $sync = false);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::getRelationValue()}.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function getRelationValue($key);
	/**
	 * Relation to the parent.
	 *
	 * @return BelongsTo<TModelClass, TModelClass>
	 */
	public function parent(): BelongsTo;

	/**
	 * Relation to children.
	 *
	 * @return HasMany<TModelClass>
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
	 * @return QueryBuilder<TModelClass>
	 */
	public function siblings(): QueryBuilder;

	/**
	 * Get the node siblings and the node itself.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function siblingsAndSelf(): QueryBuilder;

	/**
	 * Get query for the node siblings and the node itself.
	 *
	 * @param array<string> $columns
	 * @phpstan-param array<model-property<TModelClass>> $columns
	 *
	 * @return EloquentCollection<TModelClass>
	 */
	public function getSiblingsAndSelf(array $columns = ['*']): EloquentCollection;

	/**
	 * Get query for siblings after the node.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function nextSiblings(): QueryBuilder;

	/**
	 * Get query for siblings before the node.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function prevSiblings(): QueryBuilder;

	/**
	 * Get query for nodes after current node.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function nextNodes(): QueryBuilder;

	/**
	 * Get query for nodes before current node in reversed order.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function prevNodes(): QueryBuilder;

	/**
	 * Get query ancestors of the node.
	 *
	 * @return AncestorsRelation<TModelClass>
	 */
	public function ancestors(): AncestorsRelation;

	/**
	 * Make this node a root node.
	 *
	 * @return TModelClass&$this
	 */
	public function makeRoot(): static;

	/**
	 * Save node as root.
	 *
	 * @return bool
	 */
	public function saveAsRoot(): bool;

	/**
	 * Append and save a node.
	 *
	 * @param TModelClass $node
	 *
	 * @return bool
	 */
	public function appendNode(Node $node): bool;

	/**
	 * Prepend and save a node.
	 *
	 * @param TModelClass $node
	 *
	 * @return bool
	 */
	public function prependNode(Node $node): bool;

	/**
	 * Append a node to the new parent.
	 *
	 * @param TModelClass $parent
	 *
	 * @return TModelClass&$this
	 */
	public function appendToNode(Node $parent): self;

	/**
	 * Prepend a node to the new parent.
	 *
	 * @param TModelClass $parent
	 *
	 * @return TModelClass&$this
	 */
	public function prependToNode(Node $parent): self;

	/**
	 * @param TModelClass $parent
	 * @param bool $prepend
	 *
	 * @return TModelClass&$this
	 */
	public function appendOrPrependTo(Node $parent, bool $prepend = false): self;

	/**
	 * Insert self after a node.
	 *
	 * @param TModelClass $node
	 *
	 * @return TModelClass&$this
	 */
	public function afterNode(Node $node): self;

	/**
	 * Insert self before node.
	 *
	 * @param TModelClass $node
	 *
	 * @return TModelClass&$this
	 */
	public function beforeNode(Node $node): self;

	/**
	 * @param TModelClass $node
	 * @param bool $after
	 *
	 * @return TModelClass&$this
	 */
	public function beforeOrAfterNode(Node $node, bool $after = false): self;

	/**
	 * Insert self after a node and save.
	 *
	 * @param TModelClass $node
	 *
	 * @return bool
	 */
	public function insertAfterNode(Node $node): bool;

	/**
	 * Insert self before a node and save.
	 *
	 * @param TModelClass $node
	 *
	 * @return bool
	 */
	public function insertBeforeNode(Node $node): bool;

	/**
	 * @param int $lft
	 * @param int $rgt
	 * @param int|string|null $parentId
	 *
	 * @return TModelClass&$this
	 */
	public function rawNode(int $lft, int $rgt, int|string|null $parentId): self;

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
	 * @param BaseBuilder $query
	 * @return QueryBuilder<TModelClass>
	 */
	public function newEloquentBuilder($query);

	/**
	 * Get a new base query that includes deleted nodes.
	 *
	 * @param string|null $table
	 * @return QueryBuilder<TModelClass>
	 * @since 1.1
	 */
	public function newNestedSetQuery(?string $table = null): QueryBuilder;

	/**
	 * @param ?string $table
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	public function newScopedQuery(?string $table = null): QueryBuilder;

	/**
	 * @param QueryBuilder<TModelClass>|BaseBuilder   $query
	 * @param ?string $table
	 *
	 * @return QueryBuilder<TModelClass>|BaseBuilder
	 * @phpstan-return ($query is BaseBuilder ? BaseBuilder : QueryBuilder<TModelClass>)
	 */
	public function applyNestedSetScope(QueryBuilder|BaseBuilder $query, ?string $table = null): QueryBuilder|BaseBuilder;

	/**
	 * @param array<TModelClass> $models
	 * @return Collection<TModelClass>
	 */
	public function newCollection(array $models = []): Collection;

	/**
	 * Get node height (rgt - lft + 1).
	 *
	 * @return int
	 */
	public function getNodeHeight(): int;

	/**
	 * Get number of descendant nodes.
	 *
	 * @return int
	 */
	public function getDescendantCount(): int;

	/**
	 * Set the value of model's parent id key.
	 *
	 * Behind the scenes, the node is appended to found parent node.
	 *
	 * @param int|string|null $value
	 *
	 * @return TModelClass&$this
	 *
	 * @throws \Exception If parent node doesn't exists
	 */
	public function setParentIdAttribute(int|string|null $value): self;

	/**
	 * Get whether node is root.
	 *
	 * @return bool
	 */
	public function isRoot(): bool;

	/**
	 * @return bool
	 */
	public function isLeaf(): bool;

	/**
	 * Get the lft key name.
	 *
	 * @return string
	 */
	public function getLftName(): string;

	/**
	 * Get the rgt key name.
	 *
	 * @return string
	 */
	public function getRgtName(): string;

	/**
	 * Get the parent id key name.
	 *
	 * @return string
	 */
	public function getParentIdName(): string;

	/**
	 * Get the value of the model's lft key.
	 *
	 * @return int
	 */
	public function getLft(): int;

	/**
	 * Get the value of the model's rgt key.
	 *
	 * @return int
	 */
	public function getRgt(): int;

	/**
	 * Get the value of the model's parent id key.
	 *
	 * @return int|string|null
	 */
	public function getParentId(): int|string|null;

	/**
	 * Returns node that is next to current node without constraining to siblings.
	 *
	 * This can be either a next sibling or a next sibling of the parent node.
	 *
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return TModelClass
	 */
	public function getNextNode(array $columns = ['*']): self;

	/**
	 * Returns node that is before current node without constraining to siblings.
	 *
	 * This can be either a prev sibling or parent node.
	 *
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return TModelClass
	 */
	public function getPrevNode(array $columns = ['*']): self;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return Collection<TModelClass>
	 */
	public function getAncestors(array $columns = ['*']): Collection;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return Collection<TModelClass>
	 */
	public function getDescendants(array $columns = ['*']): Collection;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return Collection<TModelClass>
	 */
	public function getSiblings(array $columns = ['*']): Collection;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return Collection<TModelClass>
	 */
	public function getNextSiblings(array $columns = ['*']): Collection;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return Collection<TModelClass>
	 */
	public function getPrevSiblings(array $columns = ['*']): Collection;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return TModelClass
	 */
	public function getNextSibling(array $columns = ['*']): self;

	/**
	 * @param string[] $columns
	 * @phpstan-param array<model-property<TModelClass>|'*'> $columns
	 *
	 * @return TModelClass
	 */
	public function getPrevSibling(array $columns = ['*']): self;

	/**
	 * Get whether a node is a descendant of other node.
	 *
	 * @param TModelClass $other
	 *
	 * @return bool
	 */
	public function isDescendantOf(Node $other): bool;

	/**
	 * Get whether a node is itself or a descendant of other node.
	 *
	 * @param TModelClass $other
	 *
	 * @return bool
	 */
	public function isSelfOrDescendantOf(Node $other): bool;

	/**
	 * Get whether the node is immediate children of other node.
	 *
	 * @param TModelClass $other
	 *
	 * @return bool
	 */
	public function isChildOf(Node $other): bool;

	/**
	 * Get whether the node is a sibling of another node.
	 *
	 * @param TModelClass $other
	 *
	 * @return bool
	 */
	public function isSiblingOf(Node $other): bool;

	/**
	 * Get whether the node is an ancestor of other node, including immediate parent.
	 *
	 * @param TModelClass $other
	 *
	 * @return bool
	 */
	public function isAncestorOf(Node $other): bool;

	/**
	 * Get whether the node is itself or an ancestor of other node, including immediate parent.
	 *
	 * @param TModelClass $other
	 *
	 * @return bool
	 */
	public function isSelfOrAncestorOf(Node $other): bool;

	/**
	 * Get whether the node has moved since last save.
	 *
	 * @return bool
	 */
	public function hasMoved(): bool;

	/**
	 * @return array{int, int}
	 */
	public function getBounds(): array;

	/**
	 * @param int $value
	 *
	 * @return TModelClass&$this
	 */
	public function setLft(int $value): self;

	/**
	 * @param int $value
	 *
	 * @return TModelClass&$this
	 */
	public function setRgt(int $value): self;

	/**
	 * @param int|string|null $value
	 *
	 * @return TModelClass&$this
	 */
	public function setParentId(int|string|null $value): self;

	/**
	 * @param array|null $except
	 *
	 * @return TModelClass&$this
	 */
	public function replicate(array $except = null): self;
}
