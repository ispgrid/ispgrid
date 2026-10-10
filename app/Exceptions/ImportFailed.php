<?php

namespace App\Exceptions;

use App\Actions\Import\RowError;
use RuntimeException;

/**
 * An import found problems (or lost a race) and wrote nothing.
 */
class ImportFailed extends RuntimeException
{
    /**
     * @param  list<RowError>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The import found '.count($errors).' problem(s) and wrote nothing.');
    }
}
