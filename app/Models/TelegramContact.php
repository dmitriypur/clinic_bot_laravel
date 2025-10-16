<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramContact extends Model
{
    protected $fillable = [
        'tg_user_id',
        'tg_chat_id',
        'phone',
    ];
}
