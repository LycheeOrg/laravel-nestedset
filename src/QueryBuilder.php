<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;

/**
 * @template Tmodel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type NodeModel Node<Tmodel>&Tmodel
 *
 * @extends Builder<NodeModel>
 */
class QueryBuilder extends Builder
{
	/**
	 * @var NodeModel
	 */
	protected $model;

	/**
	 * Get node's `lft` and `rgt` values.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param bool                $required
	 *
	 * @return array<int,int>
	 */
	public function getNodeData(mixed $id, $required = false)
	{
		$query = $this->toBase();

		$query->where($this->model->getKeyName(), '=', $id);

		$data = $query->first([$this->model->getLftName(),
			$this->model->getRgtName(), ]);

		if ($data === null && $required) {
			throw new ModelNotFoundException();
		}

		return (array) $data;
	}

	/**
	 * Get plain node data.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param bool                $required
	 *
	 * @return array<int,int>
	 */
	public function getPlainNodeData(mixed $id, $required = false)
	{
		return array_values($this->getNodeData($id, $required));
	}

	/**
	 * Scope limits query to select just root node.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereIsRoot(): QueryBuilder
	{
		$this->query->whereNull($this->model->getParentIdName());

		return $this;
	}

	/**
	 * Limit results to ancestors of specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param bool                $andSelf
	 * @param string              $boolean
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereAncestorOf(mixed $id, bool $andSelf = false, string $boolean = 'and')
	{
		$keyName = $this->model->getTable() . '.' . $this->model->getKeyName();

		if (NestedSet::isNode($id)) {
			$value = '?';

			$this->query->addBinding($id->getRgt());

			$id = $id->getKey();
		} else {
			$valueQuery = $this->model
				->newQuery()
				->toBase()
				->select('_.' . $this->model->getRgtName())
				->from($this->model->getTable() . ' as _')
				->where($this->model->getKeyName(), '=', $id)
				->limit(1);

			$this->query->mergeBindings($valueQuery);

			$value = '(' . $valueQuery->toSql() . ')';
		}

		$this->query->whereNested(function ($inner) use ($value, $andSelf, $id, $keyName) {
			list($lft, $rgt) = $this->wrappedColumns();
			$wrappedTable = $this->query->getGrammar()->wrapTable($this->model->getTable());

			$inner->whereRaw("{$value} between {$wrappedTable}.{$lft} and {$wrappedTable}.{$rgt}");

			if (!$andSelf) {
				$inner->where($keyName, '<>', $id);
			}
		}, $boolean);

		return $this;
	}

	/**
	 * @param NodeModel $id
	 * @param bool                $andSelf
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function orWhereAncestorOf(mixed $id, $andSelf = false): QueryBuilder
	{
		return $this->whereAncestorOf($id, $andSelf, 'or');
	}

	/**
	 * @param NodeModel $id
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereAncestorOrSelf(mixed $id): QueryBuilder
	{
		return $this->whereAncestorOf($id, true);
	}

	/**
	 * Get ancestors of specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string[]  $columns
	 *
	 * @return EloquentCollection<int,NodeModel>
	 */
	public function ancestorsOf(mixed $id, array $columns = ['*'])
	{
		return $this->whereAncestorOf($id)->get($columns);
	}

	/**
	 * @param NodeModel $id
	 * @param string[]  $columns
	 *
	 * @return EloquentCollection<int,NodeModel>
	 */
	public function ancestorsAndSelf(mixed $id, array $columns = ['*'])
	{
		return $this->whereAncestorOf($id, true)->get($columns);
	}

	/**
	 * Add node selection statement between specified range.
	 *
	 * @since 2.0
	 *
	 * @param int[]  $values
	 * @param string $boolean
	 * @param bool   $not
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereNodeBetween(array $values, $boolean = 'and', $not = false)
	{
		$this->query->whereBetween($this->model->getTable() . '.' . $this->model->getLftName(), $values, $boolean, $not);

		return $this;
	}

	/**
	 * Add node selection statement between specified range joined with `or` operator.
	 *
	 * @since 2.0
	 *
	 * @param int[] $values
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function orWhereNodeBetween(array $values)
	{
		return $this->whereNodeBetween($values, 'or');
	}

	/**
	 * Add constraint statement to descendants of specified node.
	 *
	 * @since 2.0
	 *
	 * @param ?NodeModel $id
	 * @param string              $boolean
	 * @param bool                $not
	 * @param bool                $andSelf
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereDescendantOf(mixed $id, $boolean = 'and', $not = false,
		$andSelf = false,
	) {
		if (NestedSet::isNode($id)) {
			$data = $id->getBounds();
		} else {
			$data = $this->model->newNestedSetQuery()
								->getPlainNodeData($id, true);
		}

		// Don't include the node
		if (!$andSelf) {
			$data[0]++;
		}

		return $this->whereNodeBetween($data, $boolean, $not);
	}

	/**
	 * @param NodeModel $id
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereNotDescendantOf(mixed $id)
	{
		return $this->whereDescendantOf($id, 'and', true);
	}

	/**
	 * @param NodeModel $id
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function orWhereDescendantOf(mixed $id)
	{
		return $this->whereDescendantOf($id, 'or');
	}

	/**
	 * @param NodeModel $id
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function orWhereNotDescendantOf(mixed $id)
	{
		return $this->whereDescendantOf($id, 'or', true);
	}

	/**
	 * @param NodeModel $id
	 * @param string    $boolean
	 * @param bool      $not
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereDescendantOrSelf(mixed $id, string $boolean = 'and', bool $not = false)
	{
		return $this->whereDescendantOf($id, $boolean, $not, true);
	}

	/**
	 * Get descendants of specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string[]  $columns
	 * @param bool      $andSelf
	 *
	 * @return EloquentCollection<int,NodeModel>|Collection<Tmodel>
	 */
	public function descendantsOf(mixed $id, array $columns = ['*'], bool $andSelf = false)
	{
		try {
			return $this->whereDescendantOf($id, 'and', false, $andSelf)->get($columns);
		} catch (ModelNotFoundException $e) {
			return $this->model->newCollection();
		}
	}

	/**
	 * @param NodeModel $id
	 * @param string[]  $columns
	 *
	 * @return EloquentCollection<int,NodeModel>
	 */
	public function descendantsAndSelf($id, array $columns = ['*'])
	{
		return $this->descendantsOf($id, $columns, true);
	}

	/**
	 * @param NodeModel $id
	 * @param string    $operator
	 * @param string    $boolean
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	protected function whereIsBeforeOrAfter(mixed $id, string $operator, string $boolean)
	{
		if (NestedSet::isNode($id)) {
			$value = '?';

			$this->query->addBinding($id->getLft());
		} else {
			$valueQuery = $this->model
				->newQuery()
				->toBase()
				->select('_n.' . $this->model->getLftName())
				->from($this->model->getTable() . ' as _n')
				->where('_n.' . $this->model->getKeyName(), '=', $id);

			$this->query->mergeBindings($valueQuery);

			$value = '(' . $valueQuery->toSql() . ')';
		}

		list($lft) = $this->wrappedColumns();

		$this->query->whereRaw("{$lft} {$operator} {$value}", [], $boolean);

		return $this;
	}

	/**
	 * Constraint nodes to those that are after specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string    $boolean
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereIsAfter($id, $boolean = 'and')
	{
		return $this->whereIsBeforeOrAfter($id, '>', $boolean);
	}

	/**
	 * Constraint nodes to those that are before specified node.
	 *
	 * @since 2.0
	 *
	 * @param NodeModel $id
	 * @param string    $boolean
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereIsBefore($id, $boolean = 'and')
	{
		return $this->whereIsBeforeOrAfter($id, '<', $boolean);
	}

	/**
	 * @return QueryBuilder<Tmodel>
	 */
	public function whereIsLeaf()
	{
		list($lft, $rgt) = $this->wrappedColumns();

		return $this->whereRaw("$lft = $rgt - 1"); /** @phpstan-ignore-line */
	}

	/**
	 * @param string[] $columns
	 *
	 * @return EloquentCollection<int,NodeModel>
	 */
	public function leaves(array $columns = ['*'])
	{
		return $this->whereIsLeaf()->get($columns);
	}

	/**
	 * Include depth level into the result.
	 *
	 * @param string $as
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function withDepth($as = 'depth')
	{
		if ($this->query->columns === null) {
			$this->query->columns = ['*'];
		}

		$table = $this->wrappedTable();

		list($lft, $rgt) = $this->wrappedColumns();

		$alias = '_d';
		$wrappedAlias = $this->query->getGrammar()->wrapTable($alias);

		$query = $this->model
			->newScopedQuery('_d')
			->toBase()
			->selectRaw('count(1) - 1')
			->from($this->model->getTable() . ' as ' . $alias)
			->whereRaw("{$table}.{$lft} between {$wrappedAlias}.{$lft} and {$wrappedAlias}.{$rgt}");

		$this->query->selectSub($query, $as);

		return $this;
	}

	/**
	 * Get wrapped `lft` and `rgt` column names.
	 *
	 * @since 2.0
	 *
	 * @return string[]
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
	 * @return QueryBuilder<Tmodel>
	 */
	public function withoutRoot(): QueryBuilder
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
	 * @return QueryBuilder<Tmodel>
	 */
	public function hasParent(): QueryBuilder
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
	 * @return QueryBuilder<Tmodel>
	 */
	public function hasChildren(): QueryBuilder
	{
		list($lft, $rgt) = $this->wrappedColumns();

		$this->query->whereRaw("{$rgt} > {$lft} + 1");

		return $this;
	}

	/**
	 * Order by node position.
	 *
	 * @param string $dir
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function defaultOrder($dir = 'asc'): QueryBuilder
	{
		$this->query->orders = [];

		$this->query->orderBy($this->model->getLftName(), $dir);

		return $this;
	}

	/**
	 * Order by reversed node position.
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function reversed(): QueryBuilder
	{
		return $this->defaultOrder('desc');
	}

	/**
	 * Move a node to the new position.
	 *
	 * @param mixed $key
	 * @param int   $position
	 *
	 * @return int
	 */
	public function moveNode($key, $position)
	{
		list($lft, $rgt) = $this->model->newNestedSetQuery()
									   ->getPlainNodeData($key, true);

		if ($lft < $position && $position <= $rgt) {
			throw new \LogicException('Cannot move node into itself.');
		}

		// Get boundaries of nodes that should be moved to new position
		$from = min($lft, $position);
		$to = max($rgt, $position - 1);

		// The height of node that is being moved
		$height = $rgt - $lft + 1;

		// The distance that our node will travel to reach it's destination
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

		$boundary = [$from, $to];

		$query = $this->toBase()->where(function (Query $inner) use ($boundary) {
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
	public function makeGap($cut, $height)
	{
		$params = compact('cut', 'height');

		$query = $this->toBase()->whereNested(function (Query $inner) use ($cut) {
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
	 * @param array{height:int,cut?:int,distance?:int,lft?:int,rgt?:int,to?:int,from?:int} $params
	 *
	 * @return array<string,Expression>
	 */
	protected function patch(array $params): array
	{
		$grammar = $this->query->getGrammar();

		$columns = [];

		foreach ([$this->model->getLftName(), $this->model->getRgtName()] as $col) {
			$columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
		}

		return $columns;
	}

	/**
	 * Get patch for single column.
	 *
	 * @since 2.0
	 *
	 * @param string                                                                       $col
	 * @param array{height:int,cut?:int,distance?:int,lft?:int,rgt?:int,to?:int,from?:int} $params
	 *
	 * @return Expression
	 */
	protected function columnPatch(string $col, array $params): Expression
	{
		extract($params);

		if ($height > 0) {
			$height = '+' . $height;
		}

		if (isset($cut)) {
			return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
		}

		if (!isset($distance) || !isset($lft) || !isset($rgt) || !isset($to) || !isset($from)) {
			throw new NestedSetException('Incorrect Parameters');
		}

		if ($distance > 0) {
			$distance = '+' . $distance;
		}

		return new Expression('case ' .
							  "when {$col} between {$lft} and {$rgt} then {$col}{$distance} " . // Move the node
							  "when {$col} between {$from} and {$to} then {$col}{$height} " . // Move other nodes
							  "else {$col} end"
		);
	}

	/**
	 * Get statistics of errors of the tree.
	 *
	 * @since 2.0
	 *
	 * @return array{oddness:int,duplicates:int,wrong_parent:int,missing_parent:int}
	 */
	public function countErrors(): array
	{
		$checks = [];

		// Check if lft and rgt values are ok
		$checks['oddness'] = $this->getOdnessQuery();

		// Check if lft and rgt values are unique
		$checks['duplicates'] = $this->getDuplicatesQuery();

		// Check if parent_id is set correctly
		$checks['wrong_parent'] = $this->getWrongParentQuery();

		// Check for nodes that have missing parent
		$checks['missing_parent'] = $this->getMissingParentQuery();

		$query = $this->query->newQuery();

		foreach ($checks as $key => $inner) {
			$inner->selectRaw('count(1)');

			$query->selectSub($inner, $key);
		}

		/** @var array{oddness:int,duplicates:int,wrong_parent:int,missing_parent:int} */
		return (array) $query->first();
	}

	/**
	 * @return BaseQueryBuilder
	 */
	protected function getOdnessQuery()
	{
		return $this->model
			->newNestedSetQuery()
			->toBase()
			->whereNested(function (BaseQueryBuilder $inner) {
				list($lft, $rgt) = $this->wrappedColumns();

				$inner->whereRaw("{$lft} >= {$rgt}")
					  ->orWhereRaw("({$rgt} - {$lft}) % 2 = 0");
			});
	}

	/**
	 * @return BaseQueryBuilder
	 */
	protected function getDuplicatesQuery()
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

				$inner->orWhereRaw("{$waFirst}.{$lft}={$waSecond}.{$lft}")
					  ->orWhereRaw("{$waFirst}.{$rgt}={$waSecond}.{$rgt}")
					  ->orWhereRaw("{$waFirst}.{$lft}={$waSecond}.{$rgt}")
					  ->orWhereRaw("{$waFirst}.{$rgt}={$waSecond}.{$lft}");
			});

		return $this->model->applyNestedSetScope($query, $secondAlias);
	}

	/**
	 * @return BaseQueryBuilder
	 */
	protected function getWrongParentQuery()
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
	protected function getMissingParentQuery()
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
	public function getTotalErrors()
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
	public function isBroken()
	{
		return $this->getTotalErrors() > 0;
	}

	/**
	 * Fixes the tree based on parentage info.
	 *
	 * Nodes with invalid parent are saved as roots.
	 *
	 * @param ?NodeModel $root
	 *
	 * @return int The number of changed nodes
	 */
	public function fixTree($root = null)
	{
		$columns = [
			$this->model->getKeyName(),
			$this->model->getParentIdName(),
			$this->model->getLftName(),
			$this->model->getRgtName(),
		];

		$dictionary = $this->model
			->newNestedSetQuery()
			->when($root, function (self $query) use ($root) {
				return $query->whereDescendantOf($root);
			})
			->defaultOrder()
			->get($columns)
			->groupBy($this->model->getParentIdName())
			->all();

		return $this->fixNodes($dictionary, $root);
	}

	/**
	 * @param NodeModel $root
	 *
	 * @return int
	 */
	public function fixSubtree($root)
	{
		return $this->fixTree($root);
	}

	/**
	 * @param array<array-key,NodeModel[]> $dictionary
	 * @param NodeModel|null               $parent
	 *
	 * @return int
	 */
	protected function fixNodes(array &$dictionary, $parent = null)
	{
		$parentId = $parent !== null ? $parent->getKey() : null;
		$cut = $parent !== null ? $parent->getLft() + 1 : 1;

		$updated = [];
		$moved = 0;

		$cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);

		// Save nodes that have invalid parent as roots
		while ($dictionary !== []) {
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
	 * @param array<array-key,NodeModel[]> $dictionary
	 * @param NodeModel[]                  $updated
	 * @param ?NodeModel               $parentId
	 * @param int                          $cut
	 *
	 * @return int
	 *
	 * @internal param int $fixed
	 */
	protected static function reorderNodes(
		array &$dictionary, array &$updated, $parentId = null, $cut = 1,
	) {
		if (!array_key_exists($parentId, $dictionary)) {
			return $cut;
		}

		foreach ($dictionary[$parentId] as $model) {
			$lft = $cut;

			$cut = self::reorderNodes($dictionary, $updated, $model->getKey(), $cut + 1);

			if ($model->rawNode($lft, $cut, $parentId)->isDirty()) {
				$updated[] = $model;
			}

			$cut++;
		}

		unset($dictionary[$parentId]);

		return $cut;
	}

	/**
	 * Rebuild the tree based on raw data.
	 *
	 * If item data does not contain primary key, new node will be created.
	 *
	 * @param array<array-key,NodeModel[]>[] $data
	 * @param bool                  $delete Whether to delete nodes that exists but not in the data array
	 * @param ?NodeModel        $root
	 *
	 * @return int
	 */
	public function rebuildTree(array $data, $delete = false, $root = null)
	{
		if ($this->model->usesSoftDelete()) { /** @phpstan-ignore-line */
			$this->withTrashed(); /** @phpstan-ignore-line */
		}

		$existing = $this
			->when($root, function (self $query) use ($root) {
				return $query->whereDescendantOf($root);
			})
			->get()
			->getDictionary();

		$dictionary = [];
		$parentId = $root !== null ? $root->getKey() : null;

		$this->buildRebuildDictionary($dictionary, $data, $existing, $parentId);

		if ($existing !== null && $existing !== []) {
			if ($delete && !$this->model->usesSoftDelete()) { /** @phpstan-ignore-line */
				$this->model
					->newScopedQuery()
					->whereIn($this->model->getKeyName(), array_keys($existing))
					->delete();
			} else {
				foreach ($existing as $model) {
					$dictionary[$model->getParentId()][] = $model;

					if ($delete && $this->model->usesSoftDelete() && /** @phpstan-ignore-line */
						!$model->{$model->getDeletedAtColumn()} /** @phpstan-ignore-line */
					) {
						$time = $this->model->fromDateTime($this->model->freshTimestamp());

						$model->{$model->getDeletedAtColumn()} = $time; /** @phpstan-ignore-line */
					}
				}
			}
		}

		return $this->fixNodes($dictionary, $root);
	}

	/**
	 * @param null        $root
	 * @param array<string,mixed>[] $data
	 * @param bool                  $delete
	 *
	 * @return int
	 */
	public function rebuildSubtree($root, array $data, $delete = false)
	{
		return $this->rebuildTree($data, $delete, $root);
	}

	/**
	 * @param array<array-key,NodeModel[]> $dictionary
	 * @param array<string,mixed>[]        $data
	 * @param array<array-key,NodeModel>   $existing
	 * @param ?NodeModel               $parentId
	 */
	protected function buildRebuildDictionary(array &$dictionary,
		array $data,
		array &$existing,
		$parentId = null,
	): void {
		$keyName = $this->model->getKeyName();

		foreach ($data as $itemData) {
			/** @var NodeModel $model */
			if (!isset($itemData[$keyName])) {
				$model = $this->model->newInstance($this->model->getAttributes());

				// Set some values that will be fixed later
				$model->rawNode(0, 0, $parentId);
			} else {
				$key = $itemData[$keyName];
				if (!isset($existing[$key])) {
					throw new ModelNotFoundException();
				}

				$model = $existing[$key];

				// Disable any tree actions
				$model->rawNode($model->getLft(), $model->getRgt(), $parentId);

				unset($existing[$key]);
			}

			$model->fill(Arr::except($itemData, 'children'))->save();

			$dictionary[$parentId][] = $model;

			if (!isset($itemData['children'])) {
				continue;
			}

			$this->buildRebuildDictionary($dictionary,
				$itemData['children'],
				$existing,
				$model->getKey());
		}
	}

	/**
	 * @param string|null $table
	 *
	 * @return QueryBuilder<Tmodel>
	 */
	public function applyNestedSetScope($table = null)
	{
		return $this->model->applyNestedSetScope($this, $table);
	}

	/**
	 * Get the root node.
	 *
	 * @param string[] $columns
	 *
	 * @return NodeModel|null
	 */
	public function root(array $columns = ['*']): (Model&Node)|null
	{
		/** @var NodeModel|null */
		return $this->whereIsRoot()->first($columns);
	}
}
