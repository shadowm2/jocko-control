<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;

class AuditLogger
{
    /**
     * Metadata keys whose values are never written to the audit trail.
     */
    private const SENSITIVE_KEYS = ['token', 'plain_text_token', 'plaintexttoken', 'password', 'secret', 'authorization', 'api_key'];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AuditAction $action,
        ?User $user = null,
        Shop|string|null $shop = null,
        ShopDevice|string|null $device = null,
        array $metadata = [],
    ): AuditLog {
        if ($device instanceof ShopDevice) {
            $shop ??= $device->shop_id;
        }

        $metadata = $this->scrub($metadata);

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'shop_id' => $shop instanceof Shop ? $shop->getKey() : $shop,
            'device_id' => $device instanceof ShopDevice ? $device->getKey() : $device,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function scrub(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }
}
