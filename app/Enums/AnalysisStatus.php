<?php

namespace App\Enums;

enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Queued => __('Queued'),
            self::Processing => __('AI analysis'),
            self::Completed => __('Ready'),
            self::Failed => __('Analysis failed'),
            self::Manual => __('Manual coding'),
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }
}
