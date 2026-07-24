<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PulseMetreClip extends Model
{
    protected $fillable = [
        'filename',
        'time_signature',
        'label',
        'source',
        'license',
        'attribution',
    ];

    /**
     * Public, web-servable URL for this clip's audio file (already stored
     * under the 'public' disk - see storage/app/public/audio/pulse_metre/).
     */
    public function audioUrl(): string
    {
        return Storage::url('audio/pulse_metre/' . $this->filename);
    }
}
