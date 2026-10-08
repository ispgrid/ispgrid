<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's installation address.
 */
#[Fillable(['line1', 'line2', 'area', 'city', 'state', 'postal_code', 'country', 'latitude', 'longitude'])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;
}
