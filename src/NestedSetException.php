<?php

namespace Kalnoy\Nestedset;

/**
 * Exception thrown when something really went wrong.
 */
class NestedSetException extends \Exception
{
	public function __construct(string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}