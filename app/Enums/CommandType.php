<?php

namespace App\Enums;

/**
 * Allowlist of remote command types. Nothing here executes arbitrary code:
 * the local application decides how to carry out each well-known action.
 */
enum CommandType: string
{
    case SyncNow = 'sync_now';
    case CheckVersion = 'check_version';
    case CollectDiagnostics = 'collect_diagnostics';
    case RefreshConfig = 'refresh_config';

    public function label(): string
    {
        return __('ui.command_type.'.$this->value);
    }

    /**
     * Validation rules for the command's parameters, keyed by parameter name.
     *
     * @return array<string, array<int, mixed>>
     */
    public function payloadRules(): array
    {
        return match ($this) {
            self::SyncNow => [
                'full' => ['sometimes', 'boolean'],
            ],
            self::CheckVersion => [],
            self::CollectDiagnostics => [
                'include_logs' => ['sometimes', 'boolean'],
                'since_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            ],
            self::RefreshConfig => [],
        };
    }

    /**
     * Commands that can expose data or alter the device's behaviour and so
     * need an explicit confirmation from the administrator.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::CollectDiagnostics, self::RefreshConfig => true,
            self::SyncNow, self::CheckVersion => false,
        };
    }
}
