<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;

final class CustodyCanonicalPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', $this->json($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function json(array $payload): string
    {
        return json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new CustodyAccountingException('Floating-point values are forbidden in custody metadata.');
        }

        if (is_null($value) || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new CustodyAccountingException('Custody metadata must contain only JSON scalar values and arrays.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new CustodyAccountingException('Custody metadata object keys must be strings.');
            }
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
