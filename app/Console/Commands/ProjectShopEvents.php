<?php

namespace App\Console\Commands;

use App\Models\SyncEvent;
use App\Services\ShopRecordProjector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('jocko:project-events {--shop= : Only the events of this shop (UUID)}')]
#[Description('Rebuild the read-only shop mirror by replaying the stored synchronization events')]
class ProjectShopEvents extends Command
{
    public function handle(ShopRecordProjector $projector): int
    {
        $projected = 0;
        $failed = 0;

        SyncEvent::query()
            ->when($this->option('shop'), fn ($query, string $shop) => $query->where('shop_id', $shop))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($projector, &$projected, &$failed): void {
                foreach ($events as $event) {
                    if (! $projector->supports($event)) {
                        continue;
                    }

                    try {
                        $projector->handle($event);
                        $projected++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->components->warn("Event {$event->event_id} could not be projected: ".$e::class);
                    }
                }
            });

        $this->components->info("Projected {$projected} event(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
