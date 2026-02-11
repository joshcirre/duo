<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JoshCirre\Duo\Syncable;

class Todo extends Model
{
    use Syncable;

    protected $fillable = [
        'title',
        'description',
        'completed',
        'user_id',
    ];

    protected $casts = [
        'completed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
