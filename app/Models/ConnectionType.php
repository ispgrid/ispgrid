<?php

namespace App\Models;

use Database\Factories\ConnectionTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Home, Business, ... (editable in settings).
 */
#[Fillable(['name'])]
class ConnectionType extends Model
{
    /** @use HasFactory<ConnectionTypeFactory> */
    use HasFactory, SoftDeletes;
}
