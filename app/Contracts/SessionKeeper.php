<?php

namespace App\Contracts;

/**
 * Something that keeps its own copy of closed sessions (an add-on's archive). Before the nightly cleanup deletes
 * radacct rows, core asks every keeper which of them it already has, and deletes only those.
 */
interface SessionKeeper
{
    /**
     * The given radacct.acctuniqueid values this keeper already holds.
     *
     * @param  list<string>  $acctUniqueIds
     * @return list<string>
     */
    public function kept(array $acctUniqueIds): array;
}
