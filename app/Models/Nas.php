<?php

namespace App\Models;

use Database\Factories\NasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A MikroTik router (FreeRADIUS client). FreeRADIUS loads these rows at start.
 */
#[Fillable(['nasname', 'shortname', 'type', 'secret', 'description', 'routeros_version', 'require_message_auth'])]
#[Hidden(['secret'])]
class Nas extends Model
{
    /** @use HasFactory<NasFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'nas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['require_message_auth' => 'boolean'];
    }
}
