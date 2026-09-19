<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

class PriorityDeadlines
{
    private const DEFAULT_DAYS = [
        'low' => 10,
        'normal' => 7,
        'high' => 2,
        'urgent' => 0,
    ];

    public function all(): array
    {
        return collect(self::DEFAULT_DAYS)
            ->mapWithKeys(fn (int $days, string $priority) => [$priority => $this->days($priority)])
            ->all();
    }

    public function days(string $priority): int
    {
        if (!array_key_exists($priority, self::DEFAULT_DAYS)) {
            throw new \InvalidArgumentException('Prioridade inválida.');
        }

        $value = Setting::getValue('priority.deadline_days.'.$priority, self::DEFAULT_DAYS[$priority]);

        return filter_var($value, FILTER_VALIDATE_INT) !== false
            ? max(0, min(365, (int) $value))
            : self::DEFAULT_DAYS[$priority];
    }

    public function dueAt(string $priority): Carbon
    {
        return Carbon::now(config('app.timezone', 'America/Sao_Paulo'))
            ->addDays($this->days($priority))
            ->endOfDay();
    }

    public function save(array $days): void
    {
        foreach (self::DEFAULT_DAYS as $priority => $default) {
            Setting::setValue('priority.deadline_days.'.$priority, (int) $days[$priority]);
        }
    }
}
