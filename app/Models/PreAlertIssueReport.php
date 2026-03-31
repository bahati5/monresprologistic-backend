<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreAlertIssueReport extends Model
{
    protected $fillable = [
        'pre_alert_id',
        'reported_by_user_id',
        'message',
        'status',
    ];

    public function preAlert(): BelongsTo
    {
        return $this->belongsTo(PreAlert::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}
