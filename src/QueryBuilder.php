<?php

/**
 * Currently, PHPstorm does not yet understand the PHPStan type annotation
 * `array<string, mixed>` and wants that always to be replaced by `array`.
 * @noinspection PhpPluralMixedCanBeReplacedWithArrayInspection
 */

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Support\Arr;
use LogicException;
use Illuminate\Database\Query\Expression;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model&\Kalnoy\Nestedset\Node
 * @extends EloquentBuilder<TModelClass>
 */
class QueryBuilder extends EloquentBuilder
{
    /**
     * @var Model&Node $model
     * @phpstan-var TModelClass $model
     */
    protected $model;

	/**
	 * Execute the query as a "select" statement.
	 *
	 * @param  array<string>|string  $columns
	 * @return Collection<TModelClass>
	 */
	public function get($columns = ['*']): Collection
	{
		/**
		 * The parent method returns the Laravel standard collection,
		 * but we know that it is an instance of our special collection.
		 *
		 * @noinspection PhpIncompatibleReturnTypeInspection
		 * @phpstan-ignore-next-line
		 */
		return parent::get($columns);
	}

    /**
     * Get node's `lft` and `rgt` values.
     *
     * @since 2.0
     *
     * @param int|string $id
     * @param bool $required
     *
     * @return array{int, int}
     */
    public function getNodeData(int|string $id, bool $required = false): array
    {
        $query = $this->toBase();

        $query->where($this->model->getKeyName(), '=', $id);

        $data = $query->first([ $this->model->getLftName(),
                                $this->model->getRgtName() ]);

        if ($data === null && $required) {
			$e = new ModelNotFoundException();
			$e->setModel($this->model, [$id]);
            throw $e;
        }

	    /**
	     * This method must return an array with exactly to integers.
	     * We do so indeed as the query has been accordingly constructed so
	     * above, but PHPStan doesn't know that.
	     * @phpstan-ignore-next-line
	     */
        return (array)$data;
    }

    /**
     * Get plain node data.
     *
     * @since 2.0
     *
     * @param int|string $id
     * @param bool $required
     *
     * @return array{int, int}
     */
    public function getPlainNodeData(int|string $id, bool $required = false): array
    {
        return array_values($this->getNodeData($id, $required));
    }

    /**
     * Scope limits query to select just root node.
     *
     * @return $this
     */
    public function whereIsRoot(): self
    {
        $this->query->whereNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Limit results to ancestors of specified node.
     *
     * @since 2.0
     *
     * @param TModelClass|int|string $nodeOrId
     * @param bool $andSelf
     *
     * @param string $boolean
     *
     * @return $this
     */
    public function whereAncestorOf(Node|int|string $nodeOrId, bool $andSelf = false, string $boolean = 'and'): self
    {
        $keyName = $this->model->getTable() . '.' . $this->model->getKeyName();

        if ($nodeOrId instanceof Node) {
            $value = '?';

            $this->query->addBinding($nodeOrId->getRgt());

            $id = $nodeOrId->getKey();
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select("_.".$this->model->getRgtName())
                ->from($this->model->getTable().' as _')
                ->where($this->model->getKeyName(), '=', $nodeOrId)
                ->limit(1);

            $this->query->mergeBindings($valueQuery);

            $value = '('.$valueQuery->toSql().')';

			$id = $nodeOrId;
        }

        $this->query->whereNested(function ($inner) use ($value, $andSelf, $id, $keyName) {
            list($lft, $rgt) = $this->wrappedColumns();
            $wrappedTable = $this->query->getGrammar()->wrapTable($this->model->getTable());

            $inner->whereRaw("$value between $wrappedTable.$lft and $wrappedTable.$rgt");

            if ( ! $andSelf) {
                $inner->where($keyName, '<>', $id);
            }
        }, $boolean);


        return $this;
    }

    /**
     * @param int|string $id
     * @param bool $andSelf
     *
     * @return $this
     */
    public function orWhereAncestorOf(int|string $id, bool $andSelf = false): self
    {
        return $this->whereAncestorOf($id, $andSelf, 'or');
    }

    /**
     * @param int|string $id
     *
     * @return $this
     */
    public function whereAncestorOrSelf(int|string $id): self
    {
        return $this->whereAncestorOf($id, true);
    }

    /**
     * Get ancestors of specified node.
     *
     * @param int|string $id
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     * @since 2.0
     *
     */
    public function ancestorsOf(int|string $id, array $columns = ['*']): Collection
    {
        return $this->whereAncestorOf($id)->get($columns);
    }

    /**
     * @param int|string $id
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function ancestorsAndSelf(int|string $id, array $columns = ['*']): Collection
    {
        return $this->whereAncestorOf($id, true)->get($columns);
    }

    /**
     * Add node selection statement between specified range.
     *
     * @since 2.0
     *
     * @param array{int, int} $values
     * @param string $boolean
     * @param bool $not
     *
     * @return $this
     */
    public function whereNodeBetween(array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->query->whereBetween($this->model->getTable() . '.' . $this->model->getLftName(), $values, $boolean, $not);

        return $this;
    }

    /**
     * Add node selection statement between specified range joined with `or` operator.
     *
     * @since 2.0
     *
     * @param array{int, int} $values
     *
     * @return $this
     */
    public function orWhereNodeBetween(array $values): self
    {
        return $this->whereNodeBetween($values, 'or');
    }

    /**
     * Add constraint statement to descendants of specified node.
     *
     * @since 2.0
     *
     * @param TModelClass|int|string $modelOrId
     * @param string $boolean
     * @param bool $not
     * @param bool $andSelf
     *
     * @return $this
     */
    public function whereDescendantOf(Node|int|string $modelOrId, string $boolean = 'and', bool $not = false,
                                      bool $andSelf = false
    ): self {
        if ($modelOrId instanceof Node) {
            $data = $modelOrId->getBounds();
        } else {
            $data = $this->model->newNestedSetQuery()
                                ->getPlainNodeData($modelOrId, true);
        }

        // Don't include the node
        if ( ! $andSelf) {
            ++$data[0];
        }

        return $this->whereNodeBetween($data, $boolean, $not);
    }

    /**
     * @param TModelClass|int|string $modelOrId
     *
     * @return $this
     */
    public function whereNotDescendantOf(Node|int|string $modelOrId): self
    {
        return $this->whereDescendantOf($modelOrId, 'and', true);
    }

    /**
     * @param TModelClass|int|string $modelOrId
     *
     * @return $this
     */
    public function orWhereDescendantOf(Node|int|string $modelOrId): self
    {
        return $this->whereDescendantOf($modelOrId, 'or');
    }

    /**
     * @param TModelClass|int|string $modelOrId
     *
     * @return $this
     */
    public function orWhereNotDescendantOf(Node|int|string $modelOrId): self
    {
        return $this->whereDescendantOf($modelOrId, 'or', true);
    }

    /**
     * @param TModelClass|int|string $modelOrId
     * @param string $boolean
     * @param bool $not
     *
     * @return $this
     */
    public function whereDescendantOrSelf(Node|int|string $modelOrId, string $boolean = 'and', bool $not = false): self
    {
        return $this->whereDescendantOf($modelOrId, $boolean, $not, true);
    }

    /**
     * Get descendants of specified node.
     *
     * @since 2.0
     *
     * @param TModelClass|int|string $modelOrId
     * @param array<string> $columns
     * @param bool $andSelf
     *
     * @return Collection<TModelClass>
     */
    public function descendantsOf(Node|int|string $modelOrId, array $columns = ['*'], bool $andSelf = false): Collection
    {
        return $this->whereDescendantOf($modelOrId, 'and', false, $andSelf)->get($columns);
    }

    /**
     * @param TModelClass|int|string $modelOrId
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function descendantsAndSelf(Node|int|string $modelOrId, array $columns = ['*']): Collection
    {
        return $this->descendantsOf($modelOrId, $columns, true);
    }

    /**
     * @param TModelClass|int|string $nodeOrId
     * @param string $operator
     * @param string $boolean
     *
     * @return $this
     */
    protected function whereIsBeforeOrAfter(Node|int|string $nodeOrId, string $operator, string $boolean = 'and'): self
    {
        if ($nodeOrId instanceof Node) {
            $value = '?';

            $this->query->addBinding($nodeOrId->getLft());
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select('_n.'.$this->model->getLftName())
                ->from($this->model->getTable().' as _n')
                ->where('_n.'.$this->model->getKeyName(), '=', $nodeOrId);

            $this->query->mergeBindings($valueQuery);

            $value = '('.$valueQuery->toSql().')';
        }

        list($lft,) = $this->wrappedColumns();

        $this->query->whereRaw("{$lft} {$operator} {$value}", [ ], $boolean);

        return $this;
    }

    /**
     * Constraint nodes to those that are after specified node.
     *
     * @since 2.0
     *
     * @param TModelClass|int|string $nodeOrId
     * @param string $boolean
     *
     * @return $this
     */
    public function whereIsAfter(Node|int|string $nodeOrId, string $boolean = 'and'): self
    {
        return $this->whereIsBeforeOrAfter($nodeOrId, '>', $boolean);
    }

    /**
     * Constraint nodes to those that are before specified node.
     *
     * @since 2.0
     *
     * @param TModelClass|int|string $nodeOrId
     * @param string $boolean
     *
     * @return $this
     */
    public function whereIsBefore(Node|int|string $nodeOrId, string $boolean = 'and'): self
    {
        return $this->whereIsBeforeOrAfter($nodeOrId, '<', $boolean);
    }

    /**
     * @return $this
     */
    public function whereIsLeaf(): self
    {
        list($lft, $rgt) = $this->wrappedColumns();
        $this->query->whereRaw("$lft = $rgt - 1");

		return $this;
    }

    /**
     * @param array<string> $columns
     *
     * @return Collection<TModelClass>
     */
    public function leaves(array $columns = ['*']): Collection
    {
        return $this->whereIsLeaf()->get($columns);
    }

    /**
     * Include depth level into the result.
     *
     * @param string $as
     *
     * @return $this
     */
    public function withDepth(string $as = 'depth'): self
    {
        if ($this->query->columns === null) $this->query->columns = ['*'];

        $table = $this->wrappedTable();

        list($lft, $rgt) = $this->wrappedColumns();

        $alias = '_d';
        $wrappedAlias = $this->query->getGrammar()->wrapTable($alias);

        $query = $this->model
            ->newScopedQuery('_d')
            ->toBase()
            ->selectRaw('count(1) - 1')
            ->from($this->model->getTable().' as '.$alias)
            ->whereRaw("$table.$lft between $wrappedAlias.$lft and $wrappedAlias.$rgt");

        $this->query->selectSub($query, $as);

        return $this;
    }

    /**
     * Get wrapped `lft` and `rgt` column names.
     *
     * @since 2.0
     *
     * @return array{string, string}
     */
    protected function wrappedColumns(): array
    {
        $grammar = $this->query->getGrammar();

        return [
            $grammar->wrap($this->model->getLftName()),
            $grammar->wrap($this->model->getRgtName()),
        ];
    }

    /**
     * Get a wrapped table name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrappedTable(): string
    {
        return $this->query->getGrammar()->wrapTable($this->getQuery()->from);
    }

    /**
     * Wrap model's key name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrappedKey(): string
    {
        return $this->query->getGrammar()->wrap($this->model->getKeyName());
    }

    /**
     * Exclude root node from the result.
     *
     * @return $this
     */
    public function withoutRoot(): self
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Equivalent of `withoutRoot`.
     *
     * @since 2.0
     * @deprecated since v4.1
     *
     * @return $this
     */
    public function hasParent(): self
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Get only nodes that have children.
     *
     * @since 2.0
     * @deprecated since v4.1
     *
     * @return $this
     */
    public function hasChildren(): self
    {
        list($lft, $rgt) = $this->wrappedColumns();

        $this->query->whereRaw("$rgt > $lft + 1");

        return $this;
    }

    /**
     * Order by node position.
     *
     * @param string $dir
     *
     * @return $this
     */
    public function defaultOrder(string $dir = 'asc'): self
    {
        $this->query->orders = [];

        $this->query->orderBy($this->model->getLftName(), $dir);

        return $this;
    }

    /**
     * Order by reversed node position.
     *
     * @return $this
     */
    public function reversed(): self
    {
        return $this->defaultOrder('desc');
    }

    /**
     * Move a node to the new position.
     *
     * @param int|string $id
     * @param int $position
     *
     * @return int
     */
    public function moveNode(int|string $id, int $position): int
    {
        list($lft, $rgt) = $this->model->newNestedSetQuery()
                                       ->getPlainNodeData($id, true);

        if ($lft < $position && $position <= $rgt) {
            throw new LogicException('Cannot move node into itself.');
        }

        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to = max($rgt, $position - 1);

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach its destination
        $distance = $to - $from + 1 - $height;

        // If no distance to travel, just return
        if ($distance === 0) {
            return 0;
        }

        if ($position > $lft) {
            $height *= -1;
        } else {
            $distance *= -1;
        }

        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');

        $boundary = [ $from, $to ];

        $query = $this->toBase()->where(function (BaseQueryBuilder $inner) use ($boundary) {
            $inner->whereBetween($this->model->getLftName(), $boundary);
            $inner->orWhereBetween($this->model->getRgtName(), $boundary);
        });

        return $query->update($this->patch($params));
    }

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
    public function makeGap(int $cut, int $height): int
    {
        $params = compact('cut', 'height');

        $query = $this->toBase()->whereNested(function (BaseQueryBuilder $inner) use ($cut) {
            $inner->where($this->model->getLftName(), '>=', $cut);
            $inner->orWhere($this->model->getRgtName(), '>=', $cut);
        });

        return $query->update($this->patch($params));
    }

    /**
     * Get patch for columns.
     *
     * @since 2.0
     *
     * @param array{lft: int, rgt: int, from: int, to: int, height: int, distance: int}|array{cut: int, height: int} $params
     *
     * @return array<string, string|Expression>
     */
    protected function patch(array $params): array
    {
        $grammar = $this->query->getGrammar();

        $columns = [];

        foreach ([ $this->model->getLftName(), $this->model->getRgtName() ] as $col) {
            $columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }

    /**
     * Get patch for single column.
     *
     * @since 2.0
     *
     * @param string $col
     * @param array{lft: int, rgt: int, from: int, to: int, height: int, distance: int}|array{cut: int, height: int} $params
     *
     * @return string
     */
    protected function columnPatch(string $col, array $params): string
    {
        extract($params);

        /** @var int $height */
        if ($height > 0) $height = '+'.$height;

        if (isset($cut)) {
	        /** @var int $cut */
            return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
        }

        /** @var int $distance */
        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $from */
        /** @var int $to */
        if ($distance > 0) $distance = '+'.$distance;

        return new Expression("case ".
                              "when $col between $lft and $rgt then $col$distance ". // Move the node
                              "when $col between $from and $to then $col$height ". // Move other nodes
                              "else $col end"
        );
    }

    /**
     * Get statistics of errors of the tree.
     *
     * @since 2.0
     *
     * @return array{oddness: int, duplicates: int, wrong_parent: int, missing_parent:int}
     */
    public function countErrors(): array
    {
		/** @var array<string, BaseQueryBuilder> $checks */
        $checks = [];

        // Check if lft and rgt values are ok
        $checks['oddness'] = $this->getOdnessQuery();

        // Check if lft and rgt values are unique
        $checks['duplicates'] = $this->getDuplicatesQuery();

        // Check if parent_id is set correctly
        $checks['wrong_parent'] = $this->getWrongParentQuery();

        // Check for nodes that have missing parent
        $checks['missing_parent' ] = $this->getMissingParentQuery();

        $query = $this->query->newQuery();

        foreach ($checks as $key => $inner) {
            $inner->selectRaw('count(1)');

            $query->selectSub($inner, $key);
        }

	    /**
	     * This method must return an array with exactly to integers.
	     * We do so indeed as the query has been accordingly constructed so
	     * above, but PHPStan doesn't know that.
	     * @phpstan-ignore-next-line
	     */
	    return (array)$query->first();
    }

    /**
     * @return BaseQueryBuilder
     */
    protected function getOdnessQuery(): BaseQueryBuilder
    {
        return $this->model
            ->newNestedSetQuery()
            ->toBase()
            ->whereNested(function (BaseQueryBuilder $inner) {
                list($lft, $rgt) = $this->wrappedColumns();

                $inner->whereRaw("$lft >= $rgt")
                      ->orWhereRaw("($rgt - $lft) % 2 = 0");
            });
    }

    /**
     * @return BaseQueryBuilder
     */
    protected function getDuplicatesQuery(): BaseQueryBuilder
    {
        $table = $this->wrappedTable();
        $keyName = $this->wrappedKey();

        $firstAlias = 'c1';
        $secondAlias = 'c2';

        $waFirst = $this->query->getGrammar()->wrapTable($firstAlias);
        $waSecond = $this->query->getGrammar()->wrapTable($secondAlias);

        $query = $this->model
            ->newNestedSetQuery($firstAlias)
            ->toBase()
            ->from($this->query->raw("{$table} as {$waFirst}, {$table} {$waSecond}"))
            ->whereRaw("{$waFirst}.{$keyName} < {$waSecond}.{$keyName}")
            ->whereNested(function (BaseQueryBuilder $inner) use ($waFirst, $waSecond) {
                list($lft, $rgt) = $this->wrappedColumns();

                $inner->orWhereRaw("$waFirst.$lft}=$waSecond.$lft")
                      ->orWhereRaw("$waFirst.$rgt=$waSecond.$rgt")
                      ->orWhereRaw("$waFirst.$lft=$waSecond.$rgt")
                      ->orWhereRaw("$waFirst.$rgt=$waSecond.$lft");
            });

        return $this->model->applyNestedSetScope($query, $secondAlias);
    }

    /**
     * @return BaseQueryBuilder
     */
    protected function getWrongParentQuery(): BaseQueryBuilder
    {
        $table = $this->wrappedTable();
        $keyName = $this->wrappedKey();

        $grammar = $this->query->getGrammar();

        $parentIdName = $grammar->wrap($this->model->getParentIdName());

        $parentAlias = 'p';
        $childAlias = 'c';
        $intermAlias = 'i';

        $waParent = $grammar->wrapTable($parentAlias);
        $waChild = $grammar->wrapTable($childAlias);
        $waInterm = $grammar->wrapTable($intermAlias);

        $query = $this->model
            ->newNestedSetQuery('c')
            ->toBase()
            ->from($this->query->raw("{$table} as {$waChild}, {$table} as {$waParent}, $table as {$waInterm}"))
            ->whereRaw("{$waChild}.{$parentIdName}={$waParent}.{$keyName}")
            ->whereRaw("{$waInterm}.{$keyName} <> {$waParent}.{$keyName}")
            ->whereRaw("{$waInterm}.{$keyName} <> {$waChild}.{$keyName}")
            ->whereNested(function (BaseQueryBuilder $inner) use ($waInterm, $waChild, $waParent) {
                list($lft, $rgt) = $this->wrappedColumns();

                $inner->whereRaw("{$waChild}.{$lft} not between {$waParent}.{$lft} and {$waParent}.{$rgt}")
                      ->orWhereRaw("{$waChild}.{$lft} between {$waInterm}.{$lft} and {$waInterm}.{$rgt}")
                      ->whereRaw("{$waInterm}.{$lft} between {$waParent}.{$lft} and {$waParent}.{$rgt}");
            });

        $this->model->applyNestedSetScope($query, $parentAlias);
        $this->model->applyNestedSetScope($query, $intermAlias);

        return $query;
    }

    /**
     * @return BaseQueryBuilder
     */
    protected function getMissingParentQuery(): BaseQueryBuilder
    {
        return $this->model
            ->newNestedSetQuery()
            ->toBase()
            ->whereNested(function (BaseQueryBuilder $inner) {
                $grammar = $this->query->getGrammar();

                $table = $this->wrappedTable();
                $keyName = $this->wrappedKey();
                $parentIdName = $grammar->wrap($this->model->getParentIdName());
                $alias = 'p';
                $wrappedAlias = $grammar->wrapTable($alias);

                $existsCheck = $this->model
                    ->newNestedSetQuery()
                    ->toBase()
                    ->selectRaw('1')
                    ->from($this->query->raw("{$table} as {$wrappedAlias}"))
                    ->whereRaw("{$table}.{$parentIdName} = {$wrappedAlias}.{$keyName}")
                    ->limit(1);

                $this->model->applyNestedSetScope($existsCheck, $alias);

                $inner->whereRaw("{$parentIdName} is not null")
                      ->addWhereExistsQuery($existsCheck, 'and', true);
            });
    }

    /**
     * Get the number of total errors of the tree.
     *
     * @since 2.0
     *
     * @return int
     */
    public function getTotalErrors(): int
    {
        return array_sum($this->countErrors());
    }

    /**
     * Get whether the tree is broken.
     *
     * @since 2.0
     *
     * @return bool
     */
    public function isBroken(): bool
    {
        return $this->getTotalErrors() > 0;
    }

    /**
     * Fixes the tree based on parentage info.
     *
     * Nodes with invalid parent are saved as roots.
     *
     * @param TModelClass|null $root
     *
     * @return int The number of changed nodes
     */
    public function fixTree(?Node $root = null): int
    {
        $columns = [
            $this->model->getKeyName(),
            $this->model->getParentIdName(),
            $this->model->getLftName(),
            $this->model->getRgtName(),
        ];

		$query = $this->model->newNestedSetQuery();
		if ($root !== null) {
			$query->whereDescendantOf($root);
		}
	    $dictionary = $query
		    ->defaultOrder()
		    ->get($columns)
		    ->groupBy($this->model->getParentIdName())
		    ->all();

        return $this->fixNodes($dictionary, $root);
    }

    /**
     * @param TModelClass $root
     *
     * @return int
     */
    public function fixSubtree(Node $root): int
    {
        return $this->fixTree($root);
    }

    /**
     * @param array<int|string, array<TModelClass>> $dictionary
     * @param TModelClass|null $parent
     *
     * @return int
     */
    protected function fixNodes(array &$dictionary, ?Node $parent = null): int
    {
        $parentId = $parent?->getKey();
        $cut = $parent !== null ? $parent->getLft() + 1 : 1;

        $updated = [];
        $moved = 0;

        $cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);

        // Save nodes that have invalid parent as roots
        while (count($dictionary) !== 0) {
            $dictionary[null] = reset($dictionary);

            unset($dictionary[key($dictionary)]);

            $cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);
        }

        if ($parent !== null && ($grown = $cut - $parent->getRgt()) !== 0) {
            $moved = $this->model->newScopedQuery()->makeGap($parent->getRgt() + 1, $grown);

            $updated[] = $parent->rawNode($parent->getLft(), $cut, $parent->getParentId());
        }

        foreach ($updated as $model) {
            $model->save();
        }

        return count($updated) + $moved;
    }

    /**
     * @param array<int|string, array<TModelClass>> $dictionary
     * @param array<TModelClass> $updated
     * @param int|string|null $parentId
     * @param int $cut
     *
     * @return int
     */
    protected static function reorderNodes(
        array &$dictionary, array &$updated, int|string|null $parentId = null, int $cut = 1
    ): int {
        if ( ! isset($dictionary[$parentId])) {
            return $cut;
        }

        /** @var Node $model */
        foreach ($dictionary[$parentId] as $model) {
            $lft = $cut;

            $cut = self::reorderNodes($dictionary, $updated, $model->getKey(), $cut + 1);

            if ($model->rawNode($lft, $cut, $parentId)->isDirty()) {
                $updated[] = $model;
            }

            ++$cut;
        }

        unset($dictionary[$parentId]);

        return $cut;
    }

	/**
	 * Rebuild the tree based on raw data.
	 *
	 * If item data does not contain primary key, new node will be created.
	 *
	 * @param array<array<string, mixed>> $data
	 * @param bool $delete Whether to delete nodes that exist but not in the data
	 *                     array
	 * @param TModelClass|null $rootNode
	 *
	 * @return int
	 */
	public function rebuildTree(
		array $data, bool $delete = false, ?Node $rootNode = null
	): int {
		if ($this->model instanceof NodeWithSoftDelete) {
			/**
			 * The query instance has this method by some Laravel macro magic
			 * which is just too magic for PHPStan.
			 * @phpstan-ignore-next-line
			 */
			$this->withTrashed();
		}

		if ($rootNode !== null) {
			$this->whereDescendantOf($rootNode);
		}
		/** @var array<int|string, TModelClass> $existing */
		$existing = $this->get()->getDictionary();

		$dictionary = [];
		/** @var int|string|null $parentId */
		$parentId = $rootNode?->getKey();

		$this->buildRebuildDictionary($dictionary, $data, $existing, $parentId);

		if (count($existing) !== 0) {
			if ($delete && ! $this->model instanceof NodeWithSoftDelete) {
				$this->model
					->newScopedQuery()
					->toBase()
					->whereIn($this->model->getKeyName(), array_keys($existing))
					->delete();
			} else {
				/**
				 * @var Model&Node $model
				 * @phpstan-var TModelClass $model
				 */
				foreach ($existing as $model) {
					$dictionary[$model->getParentId()][] = $model;

					if (
						$delete &&
						$model instanceof NodeWithSoftDelete &&
						$model->getAttribute($model->getDeletedAtColumn()) === null
					) {
						$time = $model->fromDateTime($model->freshTimestamp());

						$model->setAttribute($model->getDeletedAtColumn(), $time);
					}
				}
			}
		}

		// TODO: If this line is commented out and returns a fixed value, PHPstan can analyze the code, but with this function call PHPstan crashes with an out-of-memory error.
		return $this->fixNodes($dictionary, $rootNode);
	}

	/**
	 * @param TModelClass $rootNode
	 * @param array<array<string, mixed>> $data
	 * @param bool $delete
	 *
	 * @return int
	 */
	public function rebuildSubtree(Node $rootNode, array $data, bool $delete = false): int
	{
		return $this->rebuildTree($data, $delete, $rootNode);
	}

	/**
	 * @param array<int|string|null, array<TModelClass>> $dictionary
	 * @param array<array<string, mixed>> $data
	 * @param array<int|string, TModelClass> $existing
	 * @param int|string|null $parentId
	 */
	protected function buildRebuildDictionary(array           &$dictionary,
	                                          array           $data,
	                                          array           &$existing,
	                                          int|string|null $parentId = null
	): void {
		$keyName = $this->model->getKeyName();

		/**
		 * @var array<string, mixed> $itemData
		 */
		foreach ($data as $itemData) {
			if ( ! isset($itemData[$keyName])) {
				/**
				 * @var Model&Node $model
				 * @phpstan-var TModelClass $model
				 */
				$model = $this->model->newInstance($this->model->getAttributes());

				// Set some values that will be fixed later
				$model->rawNode(0, 0, $parentId);
			} else {
				if ( ! isset($existing[$key = $itemData[$keyName]])) {
					throw new ModelNotFoundException();
				}

				/**
				 * @var Model&Node $model
				 * @phpstan-var TModelClass $model
				 */
				$model = $existing[$key];

				// Disable any tree actions
				$model->rawNode($model->getLft(), $model->getRgt(), $parentId);

				unset($existing[$key]);
			}

			$model->fill(Arr::except($itemData, 'children'))->save();

			$dictionary[$parentId][] = $model;

			if ( ! isset($itemData['children'])) continue;

			$this->buildRebuildDictionary($dictionary,
				$itemData['children'],
				$existing,
				$model->getKey());
		}
	}

    /**
     * @param string|null $table
     *
     * @return $this
     */
    public function applyNestedSetScope(string $table = null): self
    {
        $this->model->applyNestedSetScope($this, $table);

		return $this;
    }

    /**
     * Get the root node.
     *
     * @param array<string> $columns
     *
     * @return TModelClass
     */
    public function root(array $columns = ['*']): Node
    {
        return $this->whereIsRoot()->first($columns);
    }
}
