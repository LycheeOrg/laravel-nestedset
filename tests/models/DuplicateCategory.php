<?php

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Contracts\Node;
use Kalnoy\Nestedset\NodeTrait;

/**
 * @implements Node<DuplicateCategory>
 */
class DuplicateCategory extends Model implements Node
{
	use NodeTrait;

	protected $table = 'categories';

	protected $fillable = ['name'];

	public $timestamps = false;
}