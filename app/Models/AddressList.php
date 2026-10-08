<?php

namespace App\Models;

use Database\Factories\AddressListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A MikroTik firewall address list, sent as Mikrotik-Address-List.
 */
#[Fillable(['name', 'description'])]
class AddressList extends Model
{
    /** @use HasFactory<AddressListFactory> */
    use HasFactory, SoftDeletes;
}
