<?php

namespace App\Enums;

enum CommandStatus: string
{
    case Queued = 'queued';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return __('ui.command_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Expired => 'zinc',
        };
    }

    /**
     * Statuses a device may report when acknowledging a command.
     *
     * @return array<int, self>
     */
    public static function acknowledgeable(): array
    {
        return [self::Completed, self::Failed];
    }
}
