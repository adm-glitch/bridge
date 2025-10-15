<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StageChangeLog extends Model
{
    use HasFactory;

    protected $table = 'stage_change_logs';

    protected $fillable = [
        'krayin_lead_id',
        'chatwoot_conversation_id',
        'previous_stage',
        'new_stage',
        'previous_status',
        'new_status',
        'changed_at',
        'webhook_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];
}
