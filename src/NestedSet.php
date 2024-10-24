<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;

class NestedSet
{
	/**
	 * The name of default lft column.
	 */
	public const LFT = '_lft';

	/**
	 * The name of default rgt column.
	 */
	public const RGT = '_rgt';

	/**
	 * The name of default parent id column.
	 */
	public const PARENT_ID = 'parent_id';

	/**
	 * Insert direction.
	 */
	public const BEFORE = 1;

	/**
	 * Insert direction.
	 */
	public const AFTER = 2;

	/**
	 * Add default nested set columns to the table. Also create an index.
	 *
	 * @param \Illuminate\Database\Schema\Blueprint $table
	 */
	public static function columns(Blueprint $table): void
	{
		$table->unsignedInteger(self::LFT)->default(0);
		$table->unsignedInteger(self::RGT)->default(0);
		$table->unsignedInteger(self::PARENT_ID)->nullable();

		$table->index(static::getDefaultColumns());
	}

	/**
	 * Drop NestedSet columns.
	 *
	 * @param \Illuminate\Database\Schema\Blueprint $table
	 */
	public static function dropColumns(Blueprint $table): void
	{
		$columns = static::getDefaultColumns();

		$table->dropIndex($columns);
		$table->dropColumn($columns);
	}

	/**
	 * Get a list of default columns.
	 *
	 * @return string[]
	 */
	public static function getDefaultColumns(): array
	{
		return [static::LFT, static::RGT, static::PARENT_ID];
	}

	/**
	 * Replaces instanceof calls for this trait.
	 *
	 * @param mixed $node
	 *
	 * @return bool
	 */
	public static function isNode(mixed $node): bool
	{
		return $node instanceof Node;
	}
}