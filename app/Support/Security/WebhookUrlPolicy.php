<?php

declare(strict_types=1);

namespace App\Support\Security;

final class WebhookUrlPolicy
{
    public function rejectionReason(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return 'Webhook URL is invalid.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook URL must use HTTP or HTTPS.';
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return 'Webhook URL must not include embedded credentials.';
        }

        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return 'Webhook URL host is required.';
        }

        if ($this->isLocalHostname($host)) {
            return 'Webhook URL host must not be local or internal.';
        }

        if ((bool) config('webhooks.allow_private_urls', false)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? null : 'Webhook URL must not target private or reserved networks.';
        }

        foreach ($this->resolvedIpAddresses($host) as $ipAddress) {
            if (! $this->isPublicIp($ipAddress)) {
                return 'Webhook URL must not resolve to private or reserved networks.';
            }
        }

        return null;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host, '[] '), '.'));
    }

    private function isLocalHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private function isPublicIp(string $ipAddress): bool
    {
        return filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return string[]
     */
    private function resolvedIpAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
