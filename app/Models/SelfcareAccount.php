<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

/**
 * A customer account as the selfcare guard sees it: it logs in with the selfcare password (a hash), never the PPPoE
 * password, and has no "remember me". Staff log in as User on the web guard; the two never share a session key.
 */
final class SelfcareAccount extends Account implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'accounts';

    /**
     * Activity rows name the customer as an Account, the same as rows staff write.
     */
    public function getMorphClass(): string
    {
        return Account::class;
    }

    /**
     * Relations inherited from Account keep using account_id.
     */
    public function getForeignKey(): string
    {
        return 'account_id';
    }

    public function getAuthPasswordName(): string
    {
        return 'selfcare_password';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
