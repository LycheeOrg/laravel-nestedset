<?php

namespace Kalnoy\Nestedset;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model&\Kalnoy\Nestedset\NodeWithSoftDelete
 * @extends Node<TModelClass>
 *
 * @internal This whole interface would not be necessary, if Laravel would not
 * only define a bunch of traits, but also proper interfaces for each of them.
 * Hence, we repeat things here which should actually be done by Laravel
 * just to make PHPStan and any other static analyzer happy.
 */
interface NodeWithSoftDelete extends Node
{
	/**
	 * See {@link \Illuminate\Database\Eloquent\SoftDeletes::forceDelete()}.
	 *
	 * @return bool
	 */
	public function forceDelete();

	/**
	 * See {@link \Illuminate\Database\Eloquent\SoftDeletes::restore()}.
	 *
	 * @return bool|null
	 */
	public function restore();

	/**
	 * Get the name of the "deleted at" column.
	 *
	 * @return string
	 */
	public function getDeletedAtColumn();
}
