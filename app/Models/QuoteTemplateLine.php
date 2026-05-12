<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteTemplateLine extends Model
{
    protected $fillable = [
        'quote_template_id',
        'quote_line_template_id',
        'custom_value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'custom_value' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function quoteTemplate(): BelongsTo
    {
        return $this->belongsTo(QuoteTemplate::class);
    }

    public function lineTemplate(): BelongsTo
    {
        return $this->belongsTo(QuoteLineTemplate::class, 'quote_line_template_id');
    }
}
