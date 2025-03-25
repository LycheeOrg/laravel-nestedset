<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kalnoy\Nestedset\Contracts\Node;
use Kalnoy\Nestedset\NodeTrait;

/**
 * @implements Node<Category>
 */
class Category extends Model implements Node
{
	use SoftDeletes;
	use NodeTrait;

	protected $fillable = ['name', 'parent_id'];

	public $timestamps = false;

	public static function resetActionsPerformed()
	{
		static::$actionsPerformed = 0;
	}
}