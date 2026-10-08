<?php

namespace App\Models;

use Database\Factories\PoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A router address pool, sent to FreeRADIUS as Framed-Pool.
 */
#[Fillable(['name', 'description'])]
class Pool extends Model
{
    /** @use HasFactory<PoolFactory> */
    use HasFactory, SoftDeletes;
}
