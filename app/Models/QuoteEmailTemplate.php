<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteEmailTemplate extends Model
{
    protected $fillable = [
        'agency_id',
        'event',
        'subject',
        'body',
        'is_active',
        'variables',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'variables' => 'array',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function scopeForAgency($query, int $agencyId)
    {
        return $query->where('agency_id', $agencyId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function renderSubject(array $variables): string
    {
        return $this->replaceVariables($this->subject, $variables);
    }

    public function renderBody(array $variables): string
    {
        return $this->replaceVariables($this->body, $variables);
    }

    private function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }
}
