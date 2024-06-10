<?php

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Node;
use Kalnoy\Nestedset\NodeTrait;

class DuplicateCategory extends Model implements Node
{
	use NodeTrait;

	protected $table = 'categories';

	protected $fillable = ['name'];

	public $timestamps = false;
}