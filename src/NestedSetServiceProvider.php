<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
	public function register()
	{
		Blueprint::macro('nestedSet', function () {
			/** @disregard P1006 */
			NestedSet::columns($this);
		});

		Blueprint::macro('dropNestedSet', function () {
			/** @disregard P1006 */
			NestedSet::dropColumns($this);
		});
	}
}