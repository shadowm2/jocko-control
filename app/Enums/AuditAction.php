<?php

namespace App\Enums;

enum AuditAction: string
{
    case AdminLogin = 'admin.login';
    case AdminLoginFailed = 'admin.login_failed';
    case AdminLogout = 'admin.logout';
    case ShopCreated = 'shop.created';
    case ShopUpdated = 'shop.updated';
    case ShopSuspended = 'shop.suspended';
    case ShopReactivated = 'shop.reactivated';
    case DeviceCreated = 'device.created';
    case DeviceRevoked = 'device.revoked';
    case DeviceTokenRotated = 'device.token_rotated';
    case CommandQueued = 'command.queued';
    case CommandCompleted = 'command.completed';
    case CommandFailed = 'command.failed';
    case CommandExpired = 'command.expired';
    case SyncBatchRejected = 'sync.batch_rejected';
    case SyncEventConflict = 'sync.event_conflict';
    case SyncBatchError = 'sync.batch_error';
    case SyncReportedFailure = 'sync.reported_failure';

    public function label(): string
    {
        return __('ui.audit_action.'.str_replace('.', '_', $this->value));
    }
}
