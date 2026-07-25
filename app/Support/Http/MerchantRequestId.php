<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MerchantRequestId
{
    public function for(Request $request): string
    {
        $existing = $request->attributes->get('request_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $header = trim((string) $request->header('X-Request-ID'));
        $requestId = Str::isUuid($header) ? $header : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }
}
