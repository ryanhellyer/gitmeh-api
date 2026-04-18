<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIpDailyHit extends Model
{
    protected $fillable = [
        'ip',
        'day',
        'hit_count',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'hit_count' => 'integer',
        ];
    }
}
