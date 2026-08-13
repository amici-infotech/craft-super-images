<?php

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\SourceException;

final class UrlGuard
{
    /** @var list<string> */
    private array $_allowedHosts;

    public function __construct(
        array $allowedHosts,
        private int $timeoutSeconds = 10,
        private int $maxBytes = 25_000_000,
        private int $maxRedirects = 3,
    ) {
        $this->_allowedHosts = array_values(array_filter(array_map(
            static fn(string $host) => strtolower(trim($host)),
            $allowedHosts,
        )));
    }

    public function validate(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new SourceException('Remote URL cannot be empty.');
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SourceException('Remote URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SourceException('Remote URL scheme is not allowed.');
        }

        $host = strtolower($parts['host']);

        if (!$this->_isHostAllowed($host)) {
            throw new SourceException('Remote URL host is not allow-listed.');
        }

        $this->_assertNotPrivateHost($host);

        return $url;
    }

    /**
     * @return array{body: string, mime: ?string}
     */
    public function download(string $url): array
    {
        $url = $this->validate($url);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $currentUrl = $url;
        $redirects = 0;
        $body = '';

        while (true) {
            $handle = fopen($currentUrl, 'rb', false, $context);

            if ($handle === false) {
                throw new SourceException('Unable to open remote URL.');
            }

            $body = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $this->maxBytes) {
                    fclose($handle);
                    throw new SourceException('Remote URL exceeded maximum download size.');
                }
            }

            $meta = stream_get_meta_data($handle);
            fclose($handle);

            $status = $this->_extractStatusCode($http_response_header ?? []);
            $location = $this->_extractHeader($http_response_header ?? [], 'Location');

            if ($status >= 300 && $status < 400 && $location !== null) {
                if (++$redirects > $this->maxRedirects) {
                    throw new SourceException('Remote URL exceeded maximum redirects.');
                }

                $currentUrl = $this->validate($this->_resolveRedirect($currentUrl, $location));
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new SourceException('Remote URL returned an unsuccessful response.');
            }

            break;
        }

        $mime = $this->_extractHeader($http_response_header ?? [], 'Content-Type');
        if ($mime !== null) {
            $mime = trim(explode(';', $mime)[0]);
        }

        return [
            'body' => $body,
            'mime' => $mime,
        ];
    }

    private function _isHostAllowed(string $host): bool
    {
        if ($this->_allowedHosts === []) {
            return false;
        }

        foreach ($this->_allowedHosts as $allowed) {
            if ($allowed === $host) {
                return true;
            }

            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }

    private function _assertNotPrivateHost(string $host): void
    {
        if ($host === 'localhost') {
            throw new SourceException('Remote URL host is not allowed.');
        }

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        foreach ($ips as $ip) {
            if (!$this->_isPublicIp($ip)) {
                throw new SourceException('Remote URL resolves to a private network address.');
            }
        }
    }

    private function _isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @param list<string> $headers
     */
    private function _extractStatusCode(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * @param list<string> $headers
     */
    private function _extractHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function _resolveRedirect(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');

        return $scheme . '://' . $host . $dir . '/' . $location;
    }
}
