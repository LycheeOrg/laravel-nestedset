<?php

namespace tests\models;

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Contracts\Node;
use Kalnoy\Nestedset\NodeTrait;

/**
 * @implements Node<MenuItem>
 */
class MenuItem extends Model implements Node
{
	/** @use NodeTrait<MenuItem,int> */
	use NodeTrait;

	public $timestamps = false;

	protected $fillable = ['menu_id', 'parent_id'];

	public static function resetActionsPerformed(): void
	{
		static::$actionsPerformed = 0;
	}

	/**
	 * @return list<string>
	 */
	protected function getScopeAttributes(): array
	{
		return ['menu_id'];
	}
}
