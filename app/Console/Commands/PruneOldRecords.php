<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SyncEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Signature('jocko:prune {--dry-run : Only count what would be deleted}')]
#[Description('Delete synchronization events and audit entries older than their retention period')]
class PruneOldRecords extends Command
{
    private const CHUNK = 1000;

    public function handle(): int
    {
        $this->prune('sync events', 'events_days', SyncEvent::query(), 'received_at');
        $this->prune('audit entries', 'audit_days', AuditLog::query(), 'created_at');

        return self::SUCCESS;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function prune(string $label, string $setting, Builder $query, string $column): void
    {
        $days = (int) config("jocko.retention.{$setting}");

        if ($days <= 0) {
            $this->components->info("Keeping {$label} forever (retention is off).");

            return;
        }

        $old = $query->where($column, '<', now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->components->info("Would delete {$old->count()} {$label} older than {$days} days.");

            return;
        }

        $deleted = 0;

        // In chunks, so one huge delete does not hold locks for minutes.
        while (($count = (clone $old)->limit(self::CHUNK)->delete()) > 0) {
            $deleted += $count;
        }

        $this->components->info("Deleted {$deleted} {$label} older than {$days} days.");
    }
}
