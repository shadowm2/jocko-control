<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ShopDevice;
use App\Services\DeviceManagementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum. Makes sure the bearer token belongs to a device (not
 * some other principal) and that the device and its shop may still connect.
 * The shop is always taken from the authenticated device, never from the request.
 */
class AuthorizeDevice
{
    public function __construct(private readonly DeviceManagementService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        if (! $device instanceof ShopDevice || ! $device->tokenCan(DeviceManagementService::ABILITY)) {
            throw ApiException::unauthenticated();
        }

        if ($device->isRevoked()) {
            throw ApiException::unauthenticated('device_revoked', 'This device has been revoked.');
        }

        $device->loadMissing('shop');

        if ($device->shop->isSuspended()) {
            throw ApiException::forbidden('shop_suspended', 'This shop is suspended.');
        }

        $this->devices->touch($device);

        return $next($request);
    }
}
