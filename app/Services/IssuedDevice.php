<?php

namespace App\Services;

use App\Models\ShopDevice;

/**
 * A device together with the plaintext API token issued for it. The plaintext
 * exists only in this object; only its hash is persisted.
 */
final readonly class IssuedDevice
{
    public function __construct(
        public ShopDevice $device,
        public string $plainTextToken,
    ) {}
}
