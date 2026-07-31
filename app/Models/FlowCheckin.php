<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowCheckin extends Model
{
    protected $fillable = [
        'user_id',
        'absorption_rating',
        'challenge_rating',
    ];

    protected $casts = [
        'absorption_rating' => 'integer',
        'challenge_rating' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
