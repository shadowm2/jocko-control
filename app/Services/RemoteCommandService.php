<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Exceptions\ApiException;
use App\Models\RemoteCommand;
use App\Models\ShopDevice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RemoteCommandService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Queue an allowlisted command for one device.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function queue(User $admin, ShopDevice $device, CommandType $type, array $payload = [], ?CarbonInterface $expiresAt = null): RemoteCommand
    {
        $device->loadMissing('shop');

        if ($device->isRevoked()) {
            throw ValidationException::withMessages(['device' => __('ui.commands.device_revoked')]);
        }

        if ($device->shop->isSuspended()) {
            throw ValidationException::withMessages(['device' => __('ui.commands.shop_suspended')]);
        }

        $payload = $this->validatedPayload($type, $payload);
        $expiresAt = $this->validatedExpiry($expiresAt);

        return DB::transaction(function () use ($admin, $device, $type, $payload, $expiresAt): RemoteCommand {
            $command = RemoteCommand::create([
                'shop_id' => $device->shop_id,
                'device_id' => $device->getKey(),
                'command_type' => $type,
                'payload' => $payload === [] ? null : $payload,
                'requested_by' => $admin->getKey(),
                'status' => CommandStatus::Queued,
                'requested_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->audit->record(AuditAction::CommandQueued, $admin, $device->shop_id, $device, [
                'command_id' => $command->getKey(),
                'command_type' => $type->value,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return $command;
        });
    }

    /**
     * Commands the device should execute now: its own, queued, unexpired.
     * Completed, failed and expired commands are never returned.
     *
     * @return Collection<int, RemoteCommand>
     */
    public function deliverable(ShopDevice $device, int $limit): Collection
    {
        $this->expireDue($device);

        $limit = max(1, min($limit, (int) config('jocko.commands.max_deliver_per_poll')));

        $commands = RemoteCommand::query()
            ->where('device_id', $device->getKey())
            ->where('shop_id', $device->shop_id)
            ->where('status', CommandStatus::Queued->value)
            ->where('expires_at', '>', now())
            ->orderBy('requested_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($commands->isNotEmpty()) {
            RemoteCommand::query()
                ->whereIn('id', $commands->modelKeys())
                ->update(['delivered_at' => now(), 'delivery_count' => DB::raw('delivery_count + 1')]);
        }

        return $commands;
    }

    public function pendingCount(ShopDevice $device): int
    {
        return RemoteCommand::query()
            ->where('device_id', $device->getKey())
            ->where('status', CommandStatus::Queued->value)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * Record the device's report for a command it owns. Repeating an identical
     * report is harmless; contradicting a final state or reporting after expiry
     * is a conflict.
     *
     * @param  array<string, mixed>|null  $result
     *
     * @throws ApiException
     */
    public function acknowledge(ShopDevice $device, string $commandId, CommandStatus $status, ?array $result): CommandAcknowledgement
    {
        if (! in_array($status, CommandStatus::acknowledgeable(), true)) {
            throw ApiException::conflict('invalid_status', 'A command can only be acknowledged as completed or failed.');
        }

        /** @var ApiException|null $conflict */
        $conflict = null;

        $acknowledgement = DB::transaction(function () use ($device, $commandId, $status, $result, &$conflict): ?CommandAcknowledgement {
            $command = RemoteCommand::query()
                ->where('device_id', $device->getKey())
                ->where('shop_id', $device->shop_id)
                ->whereKey($commandId)
                ->lockForUpdate()
                ->first();

            if ($command === null) {
                throw ApiException::notFound('command_not_found', 'Command not found.');
            }

            if ($command->status === CommandStatus::Expired) {
                $conflict = ApiException::conflict('command_expired', 'The command has expired.');

                return null;
            }

            if (! $command->isQueued()) {
                if ($command->status === $status) {
                    return new CommandAcknowledgement($command, alreadyAcknowledged: true);
                }

                $conflict = ApiException::conflict('command_already_finalized', 'The command was already acknowledged with a different status.');

                return null;
            }

            if ($command->hasPassedExpiry()) {
                $this->expire($command);
                $conflict = ApiException::conflict('command_expired', 'The command has expired.');

                return null;
            }

            $command->update([
                'status' => $status,
                'completed_at' => now(),
                'result' => $result,
            ]);

            $this->audit->record(
                $status === CommandStatus::Completed ? AuditAction::CommandCompleted : AuditAction::CommandFailed,
                shop: $device->shop_id,
                device: $device,
                metadata: ['command_id' => $command->getKey(), 'command_type' => $command->command_type->value],
            );

            return new CommandAcknowledgement($command, alreadyAcknowledged: false);
        });

        if ($conflict !== null) {
            throw $conflict;
        }

        return $acknowledgement;
    }

    /**
     * Mark every queued command whose deadline has passed as expired.
     *
     * @return int Number of commands expired.
     */
    public function expireDue(?ShopDevice $device = null): int
    {
        $expired = 0;

        RemoteCommand::query()
            ->where('status', CommandStatus::Queued->value)
            ->where('expires_at', '<=', now())
            ->when($device, fn ($query) => $query->where('device_id', $device->getKey()))
            ->orderBy('id')
            ->chunkById(200, function ($commands) use (&$expired): void {
                foreach ($commands as $command) {
                    DB::transaction(function () use ($command, &$expired): void {
                        $locked = RemoteCommand::query()->lockForUpdate()->find($command->getKey());

                        if ($locked !== null && $locked->isQueued() && $locked->hasPassedExpiry()) {
                            $this->expire($locked);
                            $expired++;
                        }
                    });
                }
            });

        return $expired;
    }

    private function expire(RemoteCommand $command): void
    {
        $command->update(['status' => CommandStatus::Expired]);

        $this->audit->record(AuditAction::CommandExpired, shop: $command->shop_id, device: $command->device_id, metadata: [
            'command_id' => $command->getKey(),
            'command_type' => $command->command_type->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validatedPayload(CommandType $type, array $payload): array
    {
        $rules = $type->payloadRules();

        $unknown = array_diff(array_keys($payload), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['payload' => __('ui.commands.unknown_parameters', ['keys' => implode(', ', $unknown)])]);
        }

        if ($rules === []) {
            return [];
        }

        return Validator::make($payload, $rules)->validate();
    }

    /**
     * @throws ValidationException
     */
    private function validatedExpiry(?CarbonInterface $expiresAt): CarbonInterface
    {
        $expiresAt ??= now()->addMinutes((int) config('jocko.commands.default_ttl_minutes'));

        $min = now()->addMinutes((int) config('jocko.commands.min_ttl_minutes'));
        $max = now()->addMinutes((int) config('jocko.commands.max_ttl_minutes'));

        if ($expiresAt->lessThan($min) || $expiresAt->greaterThan($max)) {
            throw ValidationException::withMessages(['expiresAt' => __('ui.commands.invalid_expiry')]);
        }

        return $expiresAt;
    }
}
