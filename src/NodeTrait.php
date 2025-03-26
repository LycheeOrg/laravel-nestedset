<?php

namespace Kalnoy\Nestedset;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Support\Arr;
use Kalnoy\Nestedset\Contracts\NestedSetCollection;
use Kalnoy\Nestedset\Contracts\Node;
use Kalnoy\Nestedset\Contracts\NodeQueryBuilder;

/**
 * @template Tmodel extends \Illuminate\Database\Eloquent\Model
 * @template Tmodelkey of array-key
 *
 * @method void setRelation(string $relation, mixed $value)
 */
trait NodeTrait
{
	/**
	 * Pending operation.
	 *
	 * @var array<int,string>|null
	 */
	protected array|null $pending = null;

	/**
	 * Whether the node has moved since last save.
	 */
	protected bool $moved = false;

	public static Carbon $deletedAt;

	/**
	 * Keep track of the number of performed operations.
	 */
	public static int $actionsPerformed = 0;

	/**
	 * Sign on model events.
	 */
	public static function bootNodeTrait(): void
	{
		static::saving(function ($model) {
			return $model->callPendingAction();
		});

		static::deleting(function ($model) {
			// We will need fresh data to delete node safely
			// We must delete the descendants BEFORE we delete the actual
			// album to avoid failing FOREIGN key constraints.
			$model->refreshNode();
			$model->deleteDescendants();
		});

		if (static::usesSoftDelete()) {
			static::restoring(function ($model) { /** @phpstan-ignore staticMethod.notFound */
				static::$deletedAt = $model->{$model->getDeletedAtColumn()}; /** @phpstan-ignore property.dynamicName */
			});

			static::restored(function ($model) { /** @phpstan-ignore staticMethod.notFound */
				$model->restoreDescendants(static::$deletedAt);
			});
		}
	}

	/**
	 * Set an action.
	 *
	 * @param string $action
	 *
	 * @return self
	 */
	protected function setNodeAction($action): self
	{
		$this->pending = func_get_args();

		return $this;
	}

	/**
	 * Call pending action.
	 */
	protected function callPendingAction(): void
	{
		$this->moved = false;

		if (($this->pending === null || $this->pending === []) && !$this->exists) {
			$this->makeRoot();
		}

		if ($this->pending === null || $this->pending === []) {
			return;
		}

		$method = 'action' . ucfirst(array_shift($this->pending));
		$parameters = $this->pending;

		$this->pending = null;

		$this->moved = call_user_func_array([$this, $method], $parameters);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function usesSoftDelete(): bool
	{
		static $softDelete;

		if (is_null($softDelete)) {
			$instance = new self();

			return $softDelete = method_exists($instance, 'bootSoftDeletes'); /** @phpstan-ignore function.alreadyNarrowedType, function.impossibleType */
		}

		return $softDelete;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function actionRaw(): bool
	{
		return true;
	}

	/**
	 * Make a root node.
	 */
	protected function actionRoot(): bool
	{
		// Simplest case that do not affect other nodes.
		if (!$this->exists) {
			$cut = $this->getLowerBound() + 1;

			$this->setLft($cut);
			$this->setRgt($cut + 1);

			return true;
		}

		return $this->insertAt($this->getLowerBound() + 1);
	}

	/**
	 * Get the lower bound.
	 */
	protected function getLowerBound(): int
	{
		return (int) $this->newNestedSetQuery()->max($this->getRgtName()); /** @phpstan-ignore staticMethod.dynamicCall, cast.useless */
	}

	/**
	 * Append or prepend a node to the parent.
	 *
	 * @param Node<Tmodel> $parent
	 * @param bool         $prepend
	 *
	 * @return bool
	 */
	protected function actionAppendOrPrepend(Node $parent, $prepend = false): bool
	{
		$parent->refreshNode();

		$cut = $prepend ? $parent->getLft() + 1 : $parent->getRgt();

		if (!$this->insertAt($cut)) {
			return false;
		}

		$parent->refreshNode();

		return true;
	}

	/**
	 * Apply parent model.
	 *
	 * @param Node<Tmodel>|null $value
	 *
	 * @return self
	 */
	protected function setParent($value)
	{
		$this->setParentId($value !== null ? $value->getKey() : null)
			->setRelation('parent', $value);

		return $this;
	}

	/**
	 * Insert node before or after another node.
	 *
	 * @param Node<Tmodel> $node
	 * @param bool         $after
	 *
	 * @return bool
	 */
	protected function actionBeforeOrAfter(Node $node, $after = false)
	{
		$node->refreshNode();

		return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
	}

	/**
	 * Refresh node's crucial attributes.
	 */
	public function refreshNode(): void
	{
		if (!$this->exists || static::$actionsPerformed === 0) {
			return;
		}

		$attributes = $this->newNestedSetQuery()->getNodeData($this->getKey());

		$this->attributes = array_merge($this->attributes, $attributes);
		//        $this->original = array_merge($this->original, $attributes);
	}

	/**
	 * Relation to the parent.
	 *
	 * @return BelongsTo<Tmodel,Tmodel>
	 */
	public function parent(): BelongsTo
	{
		return $this->belongsTo(get_class($this), $this->getParentIdName()) /** @phpstan-ignore-line staticMethod.dynamicCall */
			->setModel($this);
	}

	/**
	 * Relation to children.
	 *
	 * @return HasMany<Tmodel,Tmodel>
	 */
	public function children(): HasMany
	{
		return $this->hasMany(get_class($this), $this->getParentIdName()) /** @phpstan-ignore-line staticMethod.dynamicCall */
			->setModel($this);
	}

	/**
	 * Get query for descendants of the node.
	 *
	 * @return Relation<covariant Tmodel,covariant Tmodel,NestedSetCollection<Tmodel>>
	 */
	public function descendants(): Relation
	{
		return new DescendantsRelation($this->newQuery(), $this);
	}

	/**
	 * Get query for siblings of the node.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function siblings(): NodeQueryBuilder
	{
		return $this->newScopedQuery()
			->where($this->getKeyName(), '<>', $this->getKey())
			->where($this->getParentIdName(), '=', $this->getParentId());
	}

	/**
	 * Get the node siblings and the node itself.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function siblingsAndSelf(): NodeQueryBuilder
	{
		return $this->newScopedQuery()
			->where($this->getParentIdName(), '=', $this->getParentId());
	}

	/**
	 * Get query for the node siblings and the node itself.
	 *
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function getSiblingsAndSelf(array $columns = ['*']): NestedSetCollection
	{
		return $this->siblingsAndSelf()->get($columns);
	}

	/**
	 * Get query for siblings after the node.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function nextSiblings(): NodeQueryBuilder
	{
		return $this->nextNodes()
			->where($this->getParentIdName(), '=', $this->getParentId());
	}

	/**
	 * Get query for siblings before the node.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function prevSiblings(): NodeQueryBuilder
	{
		return $this->prevNodes()
			->where($this->getParentIdName(), '=', $this->getParentId());
	}

	/**
	 * Get query for nodes after current node.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function nextNodes(): NodeQueryBuilder
	{
		return $this->newScopedQuery()
			->where($this->getLftName(), '>', $this->getLft());
	}

	/**
	 * Get query for nodes before current node in reversed order.
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function prevNodes(): NodeQueryBuilder
	{
		return $this->newScopedQuery()
			->where($this->getLftName(), '<', $this->getLft()); /** @phpstan-ignore method.notFound */
	}

	/**
	 * Get query ancestors of the node.
	 *
	 * @return Relation<covariant Tmodel,covariant Tmodel,NestedSetCollection<Tmodel>>
	 */
	public function ancestors(): Relation
	{
		return new AncestorsRelation($this->newQuery(), $this);
	}

	/**
	 * Make this node a root node.
	 *
	 * @return Node<Tmodel>
	 */
	public function makeRoot(): Node
	{
		$this->setParent(null)->dirtyBounds();

		return $this->setNodeAction('root');
	}

	/**
	 * Save node as root.
	 *
	 * @return bool
	 */
	public function saveAsRoot(): bool
	{
		if ($this->exists && $this->isRoot()) {
			return $this->save();
		}

		return $this->makeRoot()->save();
	}

	/**
	 * Append and save a node.
	 *
	 * @param Node<Tmodel> $node
	 *
	 * @return bool
	 */
	public function appendNode(Node $node): bool
	{
		/** @disregard P1013 */
		return $node->appendToNode($this)->save();
	}

	/**
	 * Prepend and save a node.
	 *
	 * @param Node<Tmodel> $node
	 *
	 * @return bool
	 */
	public function prependNode(Node $node): bool
	{
		return $node->prependToNode($this)->save();
	}

	/**
	 * Append a node to the new parent.
	 *
	 * @param Node<Tmodel> $parent
	 *
	 * @return self
	 */
	public function appendToNode(Node $parent): self
	{
		return $this->appendOrPrependTo($parent);
	}

	/**
	 * Prepend a node to the new parent.
	 *
	 * @param Node<Tmodel> $parent
	 *
	 * @return $this
	 */
	public function prependToNode(Node $parent): Node
	{
		return $this->appendOrPrependTo($parent, true); /** @phpstan-ignore return.type */
	}

	/**
	 * @param Node<Tmodel> $parent
	 * @param bool         $prepend
	 *
	 * @return self
	 */
	protected function appendOrPrependTo(Node $parent, bool $prepend = false): self
	{
		$this->assertNodeExists($parent)
			->assertNotDescendant($parent)
			->assertSameScope($parent);

		$this->setParent($parent)->dirtyBounds();

		return $this->setNodeAction('appendOrPrepend', $parent, $prepend);
	}

	/**
	 * Insert self after a node.
	 *
	 * @param Node<Tmodel> $node
	 *
	 * @return $this
	 */
	public function afterNode(Node $node)
	{
		return $this->beforeOrAfterNode($node, true); /** @phpstan-ignore return.type */
	}

	/**
	 * Insert self before node.
	 *
	 * @param Node<Tmodel> $node
	 *
	 * @return $this
	 */
	public function beforeNode(Node $node)
	{
		return $this->beforeOrAfterNode($node); /** @phpstan-ignore return.type */
	}

	/**
	 * @param Node<Tmodel> $node
	 * @param bool         $after
	 *
	 * @return Node<Tmodel>
	 */
	public function beforeOrAfterNode(Node $node, bool $after = false)
	{
		$this->assertNodeExists($node)
			->assertNotDescendant($node)
			->assertSameScope($node);

		if (!$this->isSiblingOf($node)) {
			$this->setParent($node->getRelationValue('parent'));
		}

		$this->dirtyBounds();

		return $this->setNodeAction('beforeOrAfter', $node, $after);
	}

	/**
	 * Insert self after a node and save.
	 *
	 * @param Node<Tmodel> $node
	 *
	 * @return bool
	 */
	public function insertAfterNode(Node $node)
	{
		return $this->afterNode($node)->save();
	}

	/**
	 * Insert self before a node and save.
	 *
	 * @param Node<Tmodel> $node
	 *
	 * @return bool
	 */
	public function insertBeforeNode(Node $node)
	{
		if (!$this->beforeNode($node)->save()) {
			return false;
		}

		// We'll update the target node since it will be moved
		$node->refreshNode();

		return true;
	}

	/**
	 * @param int            $lft
	 * @param int            $rgt
	 * @param Tmodelkey|null $parentId
	 *
	 * @return Node<Tmodel>
	 */
	public function rawNode(int $lft, int $rgt, mixed $parentId): Node
	{
		$this->setLft($lft)->setRgt($rgt)->setParentId($parentId);

		return $this->setNodeAction('raw');
	}

	/**
	 * Move node up given amount of positions.
	 *
	 * @param int $amount
	 *
	 * @return bool
	 */
	public function up(int $amount = 1): bool
	{
		$sibling = $this->prevSiblings()
			->defaultOrder('desc')
			->skip($amount - 1)
			->first();

		if ($sibling === null) {
			return false;
		}

		return $this->insertBeforeNode($sibling);
	}

	/**
	 * Move node down given amount of positions.
	 *
	 * @param int $amount
	 *
	 * @return bool
	 */
	public function down(int $amount = 1): bool
	{
		$sibling = $this->nextSiblings()
			->defaultOrder()
			->skip($amount - 1)
			->first();

		if ($sibling === null) {
			return false;
		}

		return $this->insertAfterNode($sibling);
	}

	/**
	 * Insert node at specific position.
	 *
	 * @param int $position
	 *
	 * @return bool
	 */
	protected function insertAt($position)
	{
		static::$actionsPerformed++;

		$result = $this->exists
			? $this->moveNode($position)
			: $this->insertNode($position);

		return $result;
	}

	/**
	 * Move a node to the new position.
	 *
	 * @since 2.0
	 *
	 * @param int $position
	 *
	 * @return bool
	 */
	protected function moveNode(int $position): bool
	{
		$updated = $this->newNestedSetQuery()
				->moveNode($this->getKey(), $position) > 0;

		if ($updated) {
			$this->refreshNode();
		}

		return $updated;
	}

	/**
	 * Insert new node at specified position.
	 *
	 * @since 2.0
	 *
	 * @param int $position
	 *
	 * @return bool
	 */
	protected function insertNode(int $position): bool
	{
		$this->newNestedSetQuery()->makeGap($position, 2);

		$height = $this->getNodeHeight();

		$this->setLft($position);
		$this->setRgt($position + $height - 1);

		return true;
	}

	/**
	 * Update the tree when the node is removed physically.
	 */
	protected function deleteDescendants(): void
	{
		$lft = $this->getLft();
		$rgt = $this->getRgt();

		$method = $this->usesSoftDelete() && $this->forceDeleting /** @phpstan-ignore property.notFound, staticMethod.dynamicCall */
			? 'forceDelete'
			: 'delete';

		// We must delete the nodes in correct order to avoid failing
		// foreign key constraints when we delete an entire subtree.
		// For MySQL we must avoid that a parent is deleted before its
		// children although the complete subtree will be deleted eventually.
		// Hence, deletion must start with the deepest node, i.e. with the
		// highest _lft value first.
		// Note: `DELETE ... ORDER BY` is non-standard SQL but required by
		// MySQL (see https://dev.mysql.com/doc/refman/8.0/en/delete.html),
		// because MySQL only supports "row consistency".
		// This means the DB must be consistent before and after every single
		// operation on a row.
		// This is contrasted by statement and transaction consistency which
		// means that the DB must be consistent before and after every
		// completed statement/transaction.
		// (See https://dev.mysql.com/doc/refman/8.0/en/ansi-diff-foreign-keys.html)
		// ANSI Standard SQL requires support for statement/transaction
		// consistency, but only PostgreSQL supports it.
		// (Good PosgreSQL :-) )
		// PostgreSQL does not support `DELETE ... ORDER BY` but also has no
		// need for it.
		// The grammar compiler removes the superfluous "ORDER BY" for
		// PostgreSQL.
		/** @phpstan-ignore method.dynamicName, staticMethod.dynamicCall */
		$this->descendants()
			->orderBy($this->getLftName(), 'desc')
			->{$method}();

		if ($this->hardDeleting()) {
			$height = $rgt - $lft + 1;

			$this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

			// In case if user wants to re-create the node
			$this->makeRoot();

			static::$actionsPerformed++;
		}
	}

	/**
	 * Restore the descendants.
	 *
	 * @param Carbon $deletedAt
	 */
	protected function restoreDescendants(Carbon $deletedAt): void
	{
		$this->descendants() 		/** @phpstan-ignore staticMethod.dynamicCall, method.notFound */
			->where($this->getDeletedAtColumn(), '>=', $deletedAt)
			->restore();
	}

	/**
	 * @param BaseQueryBuilder|EloquentBuilder<Tmodel>|QueryBuilder<Tmodel> $query
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function newEloquentBuilder($query): NodeQueryBuilder
	{
		/** @disregard P1006 */
		/** @var QueryBuilder<Tmodel> */
		return new QueryBuilder($query);
	}

	/**
	 * Get a new base query that includes deleted nodes.
	 *
	 * @since 1.1
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function newNestedSetQuery($table = null): NodeQueryBuilder
	{
		/** @phpstan-ignore staticMethod.dynamicCall */
		$builder = $this->usesSoftDelete()
			? $this->withTrashed()  /** @phpstan-ignore method.notFound, staticMethod.dynamicCall (it does exists!) */
			: $this->newQuery();

		return $this->applyNestedSetScope($builder, $table);
	}

	/**
	 * @param string $table
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public function newScopedQuery($table = null): NodeQueryBuilder
	{
		return $this->applyNestedSetScope($this->newQuery(), $table);
	}

	/**
	 * @param mixed  $query
	 * @param string $table
	 *
	 * @return mixed
	 */
	public function applyNestedSetScope($query, $table = null)
	{
		$scoped = $this->getScopeAttributes();
		if ($scoped === null || $scoped === []) { /** @phpstan-ignore identical.alwaysFalse */
			return $query;
		}

		if ($table === null) {
			$table = $this->getTable();
		}

		foreach ($scoped as $attribute) {
			$query->where($table . '.' . $attribute, '=',
				$this->getAttributeValue($attribute));
		}

		return $query;
	}

	/**
	 * @return string[]|null
	 */
	protected function getScopeAttributes()
	{
		return null;
	}

	/**
	 * @param string[] $attributes
	 *
	 * @return NodeQueryBuilder<Tmodel>
	 */
	public static function scoped(array $attributes): NodeQueryBuilder
	{
		$instance = new self();

		$instance->setRawAttributes($attributes);

		return $instance->newScopedQuery();
	}

	/**
	 * @return NestedSetCollection<Tmodel>
	 */
	public function newCollection(array $models = []): NestedSetCollection
	{
		return new Collection($models);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Use `children` key on `$attributes` to create child nodes.
	 *
	 * @param array<string,mixed> $attributes
	 * @param Node<Tmodel>        $parent
	 *
	 * @return Node<Tmodel>
	 */
	public static function create(array $attributes = [], ?Node $parent = null)
	{
		$children = Arr::pull($attributes, 'children');

		$instance = new self($attributes);

		if ($parent !== null) {
			$instance->appendToNode($parent);
		}

		$instance->save();

		// Now create children
		$relation = new EloquentCollection();

		foreach ((array) $children as $child) {
			$relation->add($child = static::create($child, $instance));

			$child->setRelation('parent', $instance);
		}

		$instance->refreshNode();
		$instance->setRelation('children', $relation);

		return $instance;
	}

	/**
	 * Get node height (rgt - lft + 1).
	 */
	public function getNodeHeight(): int
	{
		if (!$this->exists) {
			return 2;
		}

		return $this->getRgt() - $this->getLft() + 1;
	}

	/**
	 * Get number of descendant nodes.
	 */
	public function getDescendantCount(): int
	{
		return (int) ceil($this->getNodeHeight() / 2) - 1;
	}

	/**
	 * Set the value of model's parent id key.
	 *
	 * Behind the scenes node is appended to found parent node.
	 *
	 * @param Tmodelkey|null $value
	 *
	 * @throws \Exception If parent node doesn't exists
	 */
	public function setParentIdAttribute(mixed $value): void
	{
		if ($this->getParentId() === $value) {
			return;
		}

		if ($value !== null) {
			$node = $this->newScopedQuery()->findOrFail($value);
			$this->appendToNode($node);
		} else {
			$this->makeRoot();
		}
	}

	/**
	 * Get whether node is root.
	 */
	public function isRoot(): bool
	{
		return is_null($this->getParentId());
	}

	public function isLeaf(): bool
	{
		return $this->getLft() + 1 === $this->getRgt();
	}

	/**
	 * Get the lft key name.
	 */
	public function getLftName(): string
	{
		return NestedSet::LFT;
	}

	/**
	 * Get the rgt key name.
	 */
	public function getRgtName(): string
	{
		return NestedSet::RGT;
	}

	/**
	 * Get the parent id key name.
	 */
	public function getParentIdName(): string
	{
		return NestedSet::PARENT_ID;
	}

	/**
	 * Get the value of the model's lft key.
	 */
	public function getLft(): int|null
	{
		return $this->getAttributeValue($this->getLftName());
	}

	/**
	 * Get the value of the model's rgt key.
	 */
	public function getRgt(): int|null
	{
		return $this->getAttributeValue($this->getRgtName());
	}

	/**
	 * Get the value of the model's parent id key.
	 *
	 * @return Tmodelkey|null
	 */
	public function getParentId(): mixed
	{
		return $this->getAttributeValue($this->getParentIdName());
	}

	/**
	 * Returns node that is next to current node without constraining to siblings.
	 *
	 * This can be either a next sibling or a next sibling of the parent node.
	 *
	 * @param string[] $columns
	 *
	 * @return Node<Tmodel>
	 */
	public function getNextNode(array $columns = ['*']): Node
	{
		return $this->nextNodes()->defaultOrder()->first($columns);
	}

	/**
	 * Returns node that is before current node without constraining to siblings.
	 *
	 * This can be either a prev sibling or parent node.
	 *
	 * @param string[] $columns
	 *
	 * @return Node<Tmodel>
	 */
	public function getPrevNode(array $columns = ['*']): Node
	{
		return $this->prevNodes()->defaultOrder('desc')->first($columns);
	}

	/**
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function getAncestors(array $columns = ['*'])
	{
		return $this->ancestors()->get($columns); /** @phpstan-ignore return.type */
	}

	/**
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function getDescendants(array $columns = ['*'])
	{
		return $this->descendants()->get($columns); /** @phpstan-ignore return.type */
	}

	/**
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function getSiblings(array $columns = ['*'])
	{
		return $this->siblings()->get($columns);
	}

	/**
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function getNextSiblings(array $columns = ['*'])
	{
		return $this->nextSiblings()->get($columns);
	}

	/**
	 * @param string[] $columns
	 *
	 * @return NestedSetCollection<Tmodel>
	 */
	public function getPrevSiblings(array $columns = ['*'])
	{
		return $this->prevSiblings()->get($columns);
	}

	/**
	 * @param string[] $columns
	 *
	 * @return Node<Tmodel>
	 */
	public function getNextSibling(array $columns = ['*']): Node
	{
		return $this->nextSiblings()->defaultOrder()->first($columns);
	}

	/**
	 * @param string[] $columns
	 *
	 * @return Node<Tmodel>
	 */
	public function getPrevSibling(array $columns = ['*']): Node
	{
		return $this->prevSiblings()->defaultOrder('desc')->first($columns);
	}

	/**
	 * Get whether a node is a descendant of other node.
	 *
	 * @param Node<Tmodel> $other
	 *
	 * @return bool
	 */
	public function isDescendantOf(Node $other): bool
	{
		return $this->getLft() > $other->getLft() &&
			$this->getLft() < $other->getRgt();
	}

	/**
	 * Get whether a node is itself or a descendant of other node.
	 *
	 * @param Node<Tmodel> $other
	 *
	 * @return bool
	 */
	public function isSelfOrDescendantOf(Node $other)
	{
		return $this->getLft() >= $other->getLft() &&
			$this->getLft() < $other->getRgt();
	}

	/**
	 * Get whether the node is immediate children of other node.
	 *
	 * @param Node&Tmodel $other
	 *
	 * @return bool
	 */
	public function isChildOf(Node $other)
	{
		return $this->getParentId() === $other->getKey();
	}

	/**
	 * Get whether the node is a sibling of another node.
	 *
	 * @param Node<Tmodel> $other
	 *
	 * @return bool
	 */
	public function isSiblingOf(Node $other)
	{
		return $this->getParentId() === $other->getParentId();
	}

	/**
	 * Get whether the node is an ancestor of other node, including immediate parent.
	 *
	 * @param Node<Tmodel> $other
	 *
	 * @return bool
	 */
	public function isAncestorOf(Node $other): bool
	{
		return $other->isDescendantOf($this);
	}

	/**
	 * Get whether the node is itself or an ancestor of other node, including immediate parent.
	 *
	 * @param Node<Tmodel> $other
	 *
	 * @return bool
	 */
	public function isSelfOrAncestorOf(Node $other)
	{
		return $other->isSelfOrDescendantOf($this);
	}

	/**
	 * Get whether the node has moved since last save.
	 *
	 * @return bool
	 */
	public function hasMoved()
	{
		return $this->moved;
	}

	/**
	 * @return string[]
	 */
	protected function getArrayableRelations()
	{
		$result = parent::getArrayableRelations();

		// To fix #17 when converting tree to json falling to infinite recursion.
		unset($result['parent']);

		return $result;
	}

	/**
	 * Get whether user is intended to delete the model from database entirely.
	 *
	 * @return bool
	 */
	protected function hardDeleting()
	{
		return !$this->usesSoftDelete() || $this->forceDeleting; /** @phpstan-ignore property.notFound, staticMethod.dynamicCall */
	}

	/**
	 * @return array{0:int,1:int}
	 */
	public function getBounds()
	{
		return [$this->getLft(), $this->getRgt()];
	}

	/**
	 * @param $value
	 *
	 * @return Node<Tmodel> $this
	 */
	public function setLft(int $value): Node
	{
		$this->attributes[$this->getLftName()] = $value;

		return $this;
	}

	/**
	 * @param $value
	 *
	 * @return Node<Tmodel> $this
	 */
	public function setRgt(int $value): Node
	{
		$this->attributes[$this->getRgtName()] = $value;

		return $this;
	}

	/**
	 * @param Tmodelkey|null $value
	 *
	 * @return Node<Tmodel>&Tmodel
	 */
	public function setParentId(mixed $value): Node
	{
		$this->attributes[$this->getParentIdName()] = $value;

		return $this;
	}

	/**
	 * @return Node<Tmodel>
	 */
	protected function dirtyBounds()
	{
		$this->original[$this->getLftName()] = null;
		$this->original[$this->getRgtName()] = null;

		return $this;
	}

	/**
	 * @param Node<Tmodel> $node
	 *
	 * @return Node<Tmodel>
	 */
	protected function assertNotDescendant(Node $node)
	{
		if ($node === $this || $node->isDescendantOf($this)) {
			throw new \LogicException('Node must not be a descendant.');
		}

		return $this;
	}

	/**
	 * @param Node<Tmodel> $node
	 *
	 * @return Node<Tmodel>&Tmodel
	 */
	protected function assertNodeExists(Node $node)
	{
		if ($node->getLft() === null || $node->getRgt() === null) {
			throw new \LogicException('Node must exists.');
		}

		return $this;
	}

	/**
	 * @param Node<Tmodel>&Tmodel $node
	 *
	 * @phpstan-ignore generics.notSubtype
	 */
	protected function assertSameScope(Node $node): void
	{
		$scoped = $this->getScopeAttributes();
		if ($scoped === null || $scoped === []) { /** @phpstan-ignore identical.alwaysFalse */
			return;
		}

		foreach ($scoped as $attr) {
			if ($this->getAttribute($attr) !== $node->getAttribute($attr)) {
				throw new \LogicException('Nodes must be in the same scope');
			}
		}
	}

	/**
	 * @param string[]|null $except
	 *
	 * @return Node<Tmodel>&Tmodel
	 *
	 * @phpstan-ignore generics.notSubtype
	 */
	public function replicate(?array $except = null): Node
	{
		$defaults = [
			$this->getParentIdName(),
			$this->getLftName(),
			$this->getRgtName(),
		];

		$except = ($except !== null && $except !== []) ? array_unique(array_merge($except, $defaults)) : $defaults;

		return parent::replicate($except);
	}
}
