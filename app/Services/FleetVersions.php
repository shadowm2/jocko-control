<?php

namespace App\Services;

use App\Models\ShopDevice;

/**
 * Which app versions the connected devices run, and which of them are behind.
 *
 * Only plain release numbers (1.4, v2.0.1, 1.2.3@abc1234) can be put in order; a branch
 * name or a bare commit cannot, so devices on those are listed but never called outdated.
 *
 * @phpstan-type VersionRow array{version: string, devices: int, latest: bool, outdated: bool}
 */
class FleetVersions
{
    /**
     * The release number inside a reported version, or null when it is not one.
     */
    public function release(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $release = explode('@', trim($version), 2)[0];
        $release = ltrim($release, 'vV');

        return preg_match('/^\d+(\.\d+){0,3}$/', $release) === 1 ? $release : null;
    }

    /**
     * @param  iterable<string|null>  $versions
     */
    public function latest(iterable $versions): ?string
    {
        $latest = null;

        foreach ($versions as $version) {
            $release = $this->release($version);

            if ($release !== null && ($latest === null || version_compare($release, $latest, '>'))) {
                $latest = $release;
            }
        }

        return $latest;
    }

    public function isOutdated(?string $version, ?string $latest): bool
    {
        $release = $this->release($version);

        return $latest !== null && $release !== null && version_compare($release, $latest, '<');
    }

    /**
     * The versions in use among the devices that can connect, most used first.
     *
     * @return array{rows: list<VersionRow>, latest: ?string, outdated_devices: int, unreported_devices: int}
     */
    public function summary(): array
    {
        $counts = ShopDevice::query()
            ->connectable()
            ->selectRaw('shop_devices.app_version as version, count(*) as total')
            ->groupBy('shop_devices.app_version')
            ->pluck('total', 'version')
            ->all();

        // A device that never reported a version is a null key, which PHP stores as ''.
        $unreported = (int) ($counts[''] ?? 0);
        unset($counts['']);

        $latest = $this->latest(array_map('strval', array_keys($counts)));
        $rows = [];
        $outdated = 0;

        foreach ($counts as $version => $devices) {
            $version = (string) $version;
            $isOutdated = $this->isOutdated($version, $latest);
            $outdated += $isOutdated ? (int) $devices : 0;
            $rows[] = ['version' => $version, 'devices' => (int) $devices, 'latest' => $latest !== null && $this->release($version) === $latest, 'outdated' => $isOutdated];
        }

        usort($rows, fn (array $a, array $b): int => [$b['devices'], $a['version']] <=> [$a['devices'], $b['version']]);

        return ['rows' => $rows, 'latest' => $latest, 'outdated_devices' => $outdated, 'unreported_devices' => $unreported];
    }
}
