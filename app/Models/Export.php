<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    protected $fillable = [
        'completed_at',
        'file_disk',
        'file_name',
        'exporter',
        'processed_rows',
        'total_rows',
        'successful_rows',
        'user_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'processed_rows' => 'integer',
        'total_rows' => 'integer',
        'successful_rows' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFailedRowsCountAttribute(): int
    {
        return $this->total_rows - $this->successful_rows;
    }
}
