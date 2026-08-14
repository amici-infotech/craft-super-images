<?php
/**
 * Allow-listed remote URL validation and safe downloading.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\SourceException;

/**
 * URL Guard
 *
 * Validates remote source URLs against an allow-list, blocks private/reserved IP
 * ranges, and downloads content with redirect, size, and timeout limits.
 */
final class UrlGuard
{
    /**
     * Normalized lowercase allowed host patterns.
     *
     * @var list<string>
     */
    private array $_allowedHosts;

    /**
     * @param list<string> $allowedHosts Host allow-list entries; supports exact hosts and *.suffix wildcards.
     * @param int $timeoutSeconds HTTP read timeout in seconds.
     * @param int $maxBytes Maximum response body size in bytes.
     * @param int $maxRedirects Maximum number of redirects to follow.
     */
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

    /**
     * Validate a remote URL without downloading it.
     *
     * @param string $url The remote HTTP(S) URL.
     *
     * @return string The validated URL string unchanged.
     *
     * @throws SourceException When the URL is invalid, disallowed, or resolves to a private address.
     */
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
     * Validate and download a remote URL with redirect and size limits.
     *
     * @param string $url The remote HTTP(S) URL.
     *
     * @return array{body: string, mime: ?string} Downloaded body and optional Content-Type MIME.
     *
     * @throws SourceException When validation, redirect, size, or HTTP status checks fail.
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

    /**
     * Check whether a host matches the allow-list.
     *
     * @param string $host Lowercase hostname from the URL.
     *
     * @return bool True when the host is explicitly allowed or matches a wildcard entry.
     */
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

    /**
     * Reject hosts that resolve to private or reserved IP ranges.
     *
     * @param string $host Hostname or IP address to inspect.
     *
     * @return void
     *
     * @throws SourceException When localhost or a private/reserved address is detected.
     */
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

    /**
     * Determine whether an IP address is publicly routable.
     *
     * @param string $ip IPv4 or IPv6 address string.
     *
     * @return bool True when the IP is not in private or reserved ranges.
     */
    private function _isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Parse the HTTP status code from response header lines.
     *
     * @param list<string> $headers Raw HTTP response header lines.
     *
     * @return int HTTP status code, or 0 when not found.
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
     * Extract a named response header value.
     *
     * @param list<string> $headers Raw HTTP response header lines.
     * @param string $name Header name (case-insensitive).
     *
     * @return string|null Header value without the name prefix, or null when absent.
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

    /**
     * Resolve a relative redirect Location against the current URL.
     *
     * @param string $baseUrl The URL that issued the redirect.
     * @param string $location The Location header value.
     *
     * @return string Absolute redirect target URL.
     */
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
