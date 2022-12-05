<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model implements \Kalnoy\Nestedset\Node
{
	use \Illuminate\Database\Eloquent\SoftDeletes;
	use \Kalnoy\Nestedset\NodeTrait;

	protected $fillable = ['name', 'parent_id'];

	public $timestamps = false;

	public static function resetActionsPerformed()
	{
		static::$actionsPerformed = 0;
	}
}