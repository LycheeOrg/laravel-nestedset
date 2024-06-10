<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kalnoy\Nestedset\Node;
use Kalnoy\Nestedset\NodeTrait;

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