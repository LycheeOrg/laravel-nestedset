<?php

namespace Kalnoy\Nestedset;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Events\QueuedClosure;
use Illuminate\Support\Arr;
use LogicException;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model&\Kalnoy\Nestedset\Node
 */
trait NodeTrait
{
	/** @var bool $exists see {@link \Illuminate\Database\Eloquent\Model::$exists} */
	public $exists = false;

	/** @var array<string, mixed> see {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::$attributes} */
	protected $attributes = [];

	/** @var array<string, mixed> see {@link \Illuminate\Database\Eloquent\Model::$original}
	 */
	protected $original = [];

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasEvents::saving()}.
	 *
	 * @param  QueuedClosure|\Closure|string  $callback
	 * @return void
	 */
	abstract public static function saving($callback);

	/**
	 *  See {@link \Illuminate\Database\Eloquent\Concerns\HasEvents::deleting()}.
	 *
	 * @param QueuedClosure|\Closure|string  $callback
	 * @return void
	 */
	abstract public static function deleting($callback);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::getKey()}.
	 *
	 * @return mixed
	 */
	abstract public function getKey();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::save()}.
	 *
	 * @param  array{touch?: bool}  $options
	 * @return bool
	 */
	abstract public function save(array $options = []);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::getKeyName()}.
	 *
	 * @return string
	 */
	abstract public function getKeyName();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::getTable()}.
	 *
	 * @return string
	 */
	abstract public function getTable();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Model::newQuery()}.
	 *
	 * @return QueryBuilder<TModelClass>
	 */
	abstract public function newQuery();

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasRelationships::setRelation()}.
	 *
	 * @param  string  $relation
	 * @param  mixed  $value
	 * @return TModelClass&$this
	 */
	abstract public function setRelation($relation, $value);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasRelationships::belongsTo()}.
	 *
	 * @param  string  $related
	 * @param  string|null  $foreignKey
	 * @param  string|null  $ownerKey
	 * @param  string|null  $relation
	 * @return BelongsTo<TModelClass>
	 */
	abstract public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasRelationships::hasMany()}.
	 *
	 * @param string $related
	 * @param string|null $foreignKey
	 * @param string|null  $localKey
	 * @return HasMany<TModelClass>
	 */
	abstract public function hasMany($related, $foreignKey = null, $localKey = null);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::getAttributeValue()}.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	abstract public function getAttributeValue($key);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::getAttribute()}.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	abstract public function getAttribute($key);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::setRawAttributes()}.
	 *
	 * @param  array  $attributes
	 * @param  bool  $sync
	 * @return TModelClass&$this
	 */
	abstract public function setRawAttributes(array $attributes, $sync = false);

	/**
	 * See {@link \Illuminate\Database\Eloquent\Concerns\HasAttributes::getRelationValue()}.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	abstract public function getRelationValue($key);

    /**
     * Pending operation incl. its argument.
     *
     * First entry is the name of the method, all remaining entries are
     * its parameters.
     *
     * @var array<mixed>
     */
    protected array $pending = [];

    /**
     * Whether the node has moved since last save.
     */
    protected bool $moved = false;

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
    }

    /**
     * Set an action.
     *
     * @param string $action
     *
     * @return TModelClass&$this
     */
    protected function setNodeAction(string $action): self
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

        if (count($this->pending) === 0 && ! $this->exists) {
            $this->makeRoot();
        }

        if (count($this->pending) === 0) return;

        $method = 'action'.ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = [];

        $this->moved = call_user_func_array([ $this, $method ], $parameters);
    }

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
        if ( ! $this->exists) {
            $cut = $this->getLowerBound() + 1;

            $this->setLft($cut);
            $this->setRgt($cut + 1);

            return true;
        }

        return $this->insertAt($this->getLowerBound() + 1);
    }

    /**
     * Get the lower bound.
     *
     * @return int
     */
    protected function getLowerBound(): int
    {
        return (int)$this->newNestedSetQuery()->max($this->getRgtName());
    }

    /**
     * Append or prepend a node to the parent.
     *
     * @param TModelClass $parent
     * @param bool $prepend
     *
     * @return bool
     */
    protected function actionAppendOrPrepend(Node $parent, bool $prepend = false): bool
    {
        $parent->refreshNode();

        $cut = $prepend ? $parent->getLft() + 1 : $parent->getRgt();

        if ( ! $this->insertAt($cut)) {
            return false;
        }

        $parent->refreshNode();

        return true;
    }

    /**
     * Apply parent model.
     *
     * @param TModelClass|null $parentNode
     *
     * @return TModelClass&$this
     */
    protected function setParent(?Node $parentNode): self
    {
        $this->setParentId($parentNode?->getKey())
            ->setRelation('parent', $parentNode);

        return $this;
    }

    /**
     * Insert node before or after another node.
     *
     * @param TModelClass $node
     * @param bool $after
     *
     * @return bool
     */
    protected function actionBeforeOrAfter(Node $node, bool $after = false): bool
    {
        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode(): void
    {
        if ( ! $this->exists || static::$actionsPerformed === 0) return;

        $attributes = $this->newNestedSetQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
    }

    /**
     * Relation to the parent.
     *
     * @return BelongsTo<TModelClass>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Relation to children.
     *
     * @return HasMany<TModelClass>
     */
    public function children(): HasMany
    {
        return $this->hasMany(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Get query for descendants of the node.
     *
     * @return DescendantsRelation<TModelClass>
     */
    public function descendants(): DescendantsRelation
    {
        return new DescendantsRelation($this->newQuery(), $this);
    }

    /**
     * Get query for siblings of the node.
     *
     * @return QueryBuilder<TModelClass>
     */
    public function siblings(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getKeyName(), '<>', $this->getKey())
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get the node siblings and the node itself.
     *
     * @return QueryBuilder<TModelClass>
     */
    public function siblingsAndSelf(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for the node siblings and the node itself.
     *
     * @param string[] $columns
     *
     * @return EloquentCollection<TModelClass>
     */
    public function getSiblingsAndSelf(array $columns = ['*']): EloquentCollection
    {
        return $this->siblingsAndSelf()->get($columns);
    }

    /**
     * Get query for siblings after the node.
     *
     * @return QueryBuilder<TModelClass>
     */
    public function nextSiblings(): QueryBuilder
    {
        return $this->nextNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for siblings before the node.
     *
     * @return QueryBuilder<TModelClass>
     */
    public function prevSiblings(): QueryBuilder
    {
        return $this->prevNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for nodes after current node.
     *
     * @return QueryBuilder<TModelClass>
     */
    public function nextNodes(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '>', $this->getLft());
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return QueryBuilder<TModelClass>
     */
    public function prevNodes(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '<', $this->getLft());
    }

    /**
     * Get query ancestors of the node.
     *
     * @return  AncestorsRelation<TModelClass>
     */
    public function ancestors(): AncestorsRelation
    {
        return new AncestorsRelation($this->newQuery(), $this);
    }

    /**
     * Make this node a root node.
     *
     * @return TModelClass&$this
     */
    public function makeRoot(): self
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
     * @param TModelClass $node
     *
     * @return bool
     */
    public function appendNode(Node $node): bool
    {
        return $node->appendToNode($this)->save();
    }

    /**
     * Prepend and save a node.
     *
     * @param TModelClass $node
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
     * @param TModelClass $parent
     *
     * @return TModelClass&$this
     */
    public function appendToNode(Node $parent): self
    {
        return $this->appendOrPrependTo($parent);
    }

    /**
     * Prepend a node to the new parent.
     *
     * @param TModelClass $parent
     *
     * @return TModelClass&$this
     */
    public function prependToNode(Node $parent): self
    {
        return $this->appendOrPrependTo($parent, true);
    }

    /**
     * @param TModelClass $parent
     * @param bool $prepend
     *
     * @return TModelClass&$this
     */
    public function appendOrPrependTo(Node $parent, bool $prepend = false): self
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
     * @param TModelClass $node
     *
     * @return TModelClass&$this
     */
    public function afterNode(Node $node): self
    {
        return $this->beforeOrAfterNode($node, true);
    }

    /**
     * Insert self before node.
     *
     * @param TModelClass $node
     *
     * @return TModelClass&$this
     */
    public function beforeNode(Node $node): self
    {
        return $this->beforeOrAfterNode($node);
    }

    /**
     * @param TModelClass $node
     * @param bool $after
     *
     * @return TModelClass&$this
     */
    public function beforeOrAfterNode(Node $node, bool $after = false): self
    {
        $this->assertNodeExists($node)
            ->assertNotDescendant($node)
            ->assertSameScope($node);

        if ( ! $this->isSiblingOf($node)) {
            $this->setParent($node->getRelationValue('parent'));
        }

        $this->dirtyBounds();

        return $this->setNodeAction('beforeOrAfter', $node, $after);
    }

    /**
     * Insert self after a node and save.
     *
     * @param TModelClass $node
     *
     * @return bool
     */
    public function insertAfterNode(Node $node): bool
    {
        return $this->afterNode($node)->save();
    }

    /**
     * Insert self before a node and save.
     *
     * @param TModelClass $node
     *
     * @return bool
     */
    public function insertBeforeNode(Node $node): bool
    {
        if ( ! $this->beforeNode($node)->save()) return false;

        // We'll update the target node since it will be moved
        $node->refreshNode();

        return true;
    }

    /**
     * @param int $lft
     * @param int $rgt
     * @param int|string|null $parentId
     *
     * @return TModelClass&$this
     */
    public function rawNode(int $lft, int $rgt, int|string|null $parentId): self
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

        if ( ! $sibling) return false;

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

        if ( ! $sibling) return false;

        return $this->insertAfterNode($sibling);
    }

    /**
     * Insert node at specific position.
     *
     * @param  int $position
     *
     * @return bool
     */
    protected function insertAt(int $position): bool
    {
        ++static::$actionsPerformed;

	    return $this->exists
	        ? $this->moveNode($position)
	        : $this->insertNode($position);
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

        if ($updated) $this->refreshNode();

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
        $this->descendants()
            ->orderBy($this->getLftName(), 'desc')
            ->forceDelete();

        $height = $rgt - $lft + 1;

        $this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

        // In case if user wants to re-create the node
        $this->makeRoot();

        static::$actionsPerformed++;
    }

	/**
	 * @param BaseBuilder $query
	 * @return QueryBuilder<TModelClass>
	 */
    public function newEloquentBuilder($query)
    {
        return new QueryBuilder($query);
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
        return $this->applyNestedSetScope($this->newQuery(), $table);
    }

    /**
     * @param string|null $table
     *
     * @return QueryBuilder<TModelClass>
     */
    public function newScopedQuery(?string $table = null): QueryBuilder
    {
        return $this->applyNestedSetScope($this->newQuery(), $table);
    }

    /**
     * @param QueryBuilder<TModelClass>|BaseBuilder $query
     * @param string|null $table
     *
     * @return QueryBuilder|BaseBuilder
     * @phpstan-return ($query is BaseBuilder ? BaseBuilder : QueryBuilder<TModelClass>)
     */
    public function applyNestedSetScope(QueryBuilder|BaseBuilder $query, ?string $table = null): QueryBuilder|BaseBuilder
    {
        if ( ! $scoped = $this->getScopeAttributes()) {
            return $query;
        }

        if ( ! $table) {
            $table = $this->getTable();
        }

        foreach ($scoped as $attribute) {
            $query->where($table.'.'.$attribute, '=',
                          $this->getAttributeValue($attribute));
        }

        return $query;
    }

    /**
     * @return string[]
     */
    protected function getScopeAttributes(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return QueryBuilder<TModelClass>
     */
    public static function scoped(array $attributes): QueryBuilder
    {
        $instance = new static();

        $instance->setRawAttributes($attributes);

        return $instance->newScopedQuery();
    }

    /**
     * @param array<TModelClass> $models
     *
     * @return Collection<TModelClass>
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Use `children` key on `$attributes` to create child nodes.
     *
     * @param array<string, mixed> $attributes
     * @param TModelClass|null $parent
     *
     * @return TModelClass&self
     */
    public static function create(array $attributes = [], ?Node $parent = null): self
    {
        $children = Arr::pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) {
            $instance->appendToNode($parent);
        }

        $instance->save();

        // Now create children
        $relation = new EloquentCollection;

        foreach ((array)$children as $child) {
            $relation->add($child = static::create($child, $instance));

            $child->setRelation('parent', $instance);
        }

        $instance->refreshNode();

        return $instance->setRelation('children', $relation);
    }

    /**
     * Get node height (rgt - lft + 1).
     *
     * @return int
     */
    public function getNodeHeight(): int
    {
        if ( ! $this->exists) return 2;

        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Get number of descendant nodes.
     *
     * @return int
     */
    public function getDescendantCount(): int
    {
        return ceil($this->getNodeHeight() / 2) - 1;
    }

    /**
     * Set the value of model's parent id key.
     *
     * Behind the scenes node is appended to found parent node.
     *
     * @param int|string|null $id
     *
     * @return TModelClass&$this
     *
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute(int|string|null $id): self
    {
        if ($this->getParentId() === $id) return $this;

        if ($id !== null) {
            $this->appendToNode($this->newScopedQuery()->findOrFail($id));
        } else {
            $this->makeRoot();
        }

		return $this;
    }

    /**
     * Get whether node is root.
     *
     * @return boolean
     */
    public function isRoot(): bool
    {
        return $this->getParentId() === null;
    }

    /**
     * @return bool
     */
    public function isLeaf(): bool
    {
        return $this->getLft() + 1 === $this->getRgt();
    }

    /**
     * Get the lft key name.
     *
     * @return  string
     */
    public function getLftName(): string
    {
        return NestedSet::LFT;
    }

    /**
     * Get the rgt key name.
     *
     * @return  string
     */
    public function getRgtName(): string
    {
        return NestedSet::RGT;
    }

    /**
     * Get the parent id key name.
     *
     * @return  string
     */
    public function getParentIdName(): string
    {
        return NestedSet::PARENT_ID;
    }

    /**
     * Get the value of the model's lft key.
     *
     * @return  integer
     */
    public function getLft(): int
    {
        return $this->getAttributeValue($this->getLftName());
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt(): int
    {
        return $this->getAttributeValue($this->getRgtName());
    }

    /**
     * Get the value of the model's parent id key.
     *
     * @return int|string|null
     */
    public function getParentId(): int|string|null
    {
        return $this->getAttributeValue($this->getParentIdName());
    }

    /**
     * Returns node that is next to current node without constraining to siblings.
     *
     * This can be either a next sibling or a next sibling of the parent node.
     *
     * @param array $columns
     *
     * @return TModelClass&self
     */
    public function getNextNode(array $columns = ['*']): self
    {
        return $this->nextNodes()->defaultOrder()->first($columns);
    }

    /**
     * Returns node that is before current node without constraining to siblings.
     *
     * This can be either a prev sibling or parent node.
     *
     * @param array<string> $columns
     *
     * @return TModelClass&$this
     */
    public function getPrevNode(array $columns = ['*']): self
    {
        return $this->prevNodes()->defaultOrder('desc')->first($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function getAncestors(array $columns = ['*']): Collection
    {
        return $this->ancestors()->get($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function getDescendants(array $columns = ['*']): Collection
    {
        return $this->descendants()->get($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function getSiblings(array $columns = ['*']): Collection
    {
        return $this->siblings()->get($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function getNextSiblings(array $columns = ['*']): Collection
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function getPrevSiblings(array $columns = ['*']): Collection
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return TModelClass&self
     */
    public function getNextSibling(array $columns = ['*']): self
    {
        return $this->nextSiblings()->defaultOrder()->first($columns);
    }

    /**
     * @param array<string> $columns
     *
     * @return TModelClass&self
     */
    public function getPrevSibling(array $columns = ['*']): self
    {
        return $this->prevSiblings()->defaultOrder('desc')->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     *
     * @param TModelClass $other
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
     * @param TModelClass $other
     *
     * @return bool
     */
    public function isSelfOrDescendantOf(Node $other): bool
    {
        return $this->getLft() >= $other->getLft() &&
            $this->getLft() < $other->getRgt();
    }

    /**
     * Get whether the node is immediate children of other node.
     *
     * @param TModelClass $other
     *
     * @return bool
     */
    public function isChildOf(Node $other): bool
    {
        return $this->getParentId() === $other->getKey();
    }

    /**
     * Get whether the node is a sibling of another node.
     *
     * @param TModelClass $other
     *
     * @return bool
     */
    public function isSiblingOf(Node $other): bool
    {
        return $this->getParentId() === $other->getParentId();
    }

    /**
     * Get whether the node is an ancestor of other node, including immediate parent.
     *
     * @param TModelClass $other
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
     * @param TModelClass $other
     *
     * @return bool
     */
    public function isSelfOrAncestorOf(Node $other): bool
    {
        return $other->isSelfOrDescendantOf($this);
    }

    /**
     * Get whether the node has moved since last save.
     *
     * @return bool
     */
    public function hasMoved(): bool
    {
        return $this->moved;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getArrayableRelations(): array
    {
        $result = parent::getArrayableRelations();

        // To fix #17 when converting tree to json falling to infinite recursion.
        unset($result['parent']);

        return $result;
    }

    /**
     * @return array
     * @phpstan-return array{int, int}
     */
    public function getBounds(): array
    {
        return [ $this->getLft(), $this->getRgt() ];
    }

    /**
     * @param int $value
     *
     * @return TModelClass&$this
     */
    public function setLft(int $value): self
    {
        $this->attributes[$this->getLftName()] = $value;

        return $this;
    }

    /**
     * @param int $value
     *
     * @return TModelClass&$this
     */
    public function setRgt(int $value): self
    {
        $this->attributes[$this->getRgtName()] = $value;

        return $this;
    }

    /**
     * @param int|string|null $value
     *
     * @return TModelClass&$this
     */
    public function setParentId(int|string|null $value): self
    {
        $this->attributes[$this->getParentIdName()] = $value;

        return $this;
    }

    /**
     * @return TModelClass&$this
     */
    protected function dirtyBounds(): self
    {
        $this->original[$this->getLftName()] = null;
        $this->original[$this->getRgtName()] = null;

        return $this;
    }

    /**
     * @param TModelClass $node
     *
     * @return TModelClass&$this
     */
    protected function assertNotDescendant(Node $node): self
    {
        if ($node === $this || $node->isDescendantOf($this)) {
            throw new LogicException('Node must not be a descendant.');
        }

        return $this;
    }

    /**
     * @param TModelClass $node
     *
     * @return TModelClass&$this
     */
    protected function assertNodeExists(Node $node): self
    {
        if ( ! $node->getLft() || ! $node->getRgt()) {
            throw new LogicException('Node must exists.');
        }

        return $this;
    }

    /**
     * @param TModelClass $node
     * @return TModelClass&$this
     */
    protected function assertSameScope(Node $node): self
    {
        if ( ! $scoped = $this->getScopeAttributes()) {
            return $this;
        }

        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                throw new LogicException('Nodes must be in the same scope');
            }
        }

	    return $this;
    }

    /**
     * @param array|null $except
     *
     * @return TModelClass&self
     */
    public function replicate(array $except = null): self
    {
        $defaults = [
            $this->getParentIdName(),
            $this->getLftName(),
            $this->getRgtName(),
        ];

        $except = $except ? array_unique(array_merge($except, $defaults)) : $defaults;

        return parent::replicate($except);
    }
}
