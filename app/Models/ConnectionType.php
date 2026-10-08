<?php

namespace App\Models;

use Database\Factories\ConnectionTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Home, Business, ... (editable in settings).
 */
#[Fillable(['name'])]
class ConnectionType extends Model
{
    /** @use HasFactory<ConnectionTypeFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
