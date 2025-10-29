<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use HasFactory, Syncable;

    protected $fillable = [
        'title',
        'completed',
        'description',
    ];

    protected $casts = [
        'completed' => 'boolean',
    ];
}
