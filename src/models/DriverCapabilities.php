<?php
/**
 * Feature flags and supported operations for an image driver.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Driver Capabilities
 *
 * Describes which transforms and output formats a driver implements, plus alpha and watermark support.
 */
final class DriverCapabilities
{
    /**
     * @param list<string> $operations Transform slugs supported by the driver (e.g. `resize`, `crop`).
     * @param list<string> $formats Output format slugs the driver can encode natively.
     * @param bool $supportsAlpha Whether the driver preserves alpha channels through transforms.
     * @param bool $supportsWatermark Whether the driver implements watermark operations.
     */
    public function __construct(
        public readonly array $operations = [],
        public readonly array $formats = [],
        public readonly bool $supportsAlpha = true,
        public readonly bool $supportsWatermark = false,
    ) {
    }
}
