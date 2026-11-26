<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Enums;

enum AlertType: string
{
    case LateStart = 'late_start';
    case DeadlineAtRisk = 'deadline_at_risk';

    public function priority(): AlertPriority
    {
        return match ($this) {
            self::LateStart => AlertPriority::Medium,
            self::DeadlineAtRisk => AlertPriority::High,
        };
    }

    public function action(): AlertAction
    {
        return match ($this) {
            self::LateStart => AlertAction::ContactProvider,
            self::DeadlineAtRisk => AlertAction::ReassignOrHelp,
        };
    }
}