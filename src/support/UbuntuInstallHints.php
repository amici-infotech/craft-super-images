<?php
/**
 * Ubuntu apt install hints for missing drivers and optimizer binaries.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\support;

/**
 * Ubuntu Install Hints
 *
 * Provides apt-focused install commands and notes for the diagnostics doctor.
 * Live servers are assumed to be Ubuntu; hints are intentionally apt-focused.
 */
final class UbuntuInstallHints
{
    /**
     * Return an install hint for a missing image driver.
     *
     * @param string $driver Driver name: gd, imagick, or libvips.
     *
     * @return array{package: string, command: string, notes: string}|null Hint array, or null for unknown drivers.
     */
    public static function forDriver(string $driver): ?array
    {
        return match (strtolower($driver)) {
            'gd' => [
                'package' => 'php8.x-gd',
                'command' => 'sudo apt-get install -y php8.3-gd && sudo systemctl restart php8.3-fpm',
                'notes' => 'Replace 8.3 with your FPM PHP version. Confirm gd in web phpinfo() (CLI alone is not enough). See docs/drivers.md#gd.',
            ],
            'imagick' => [
                'package' => 'php8.x-imagick',
                'command' => 'sudo apt-get install -y php8.3-imagick && sudo systemctl restart php8.3-fpm',
                'notes' => 'Replace 8.3 with your FPM PHP version. If MagickWand mismatches, reinstall php-imagick + imagemagick. See docs/drivers.md#imagick.',
            ],
            'libvips' => [
                'package' => 'libvips42 / libvips-tools + jcupitt/vips + FFI',
                'command' => 'sudo apt-get install -y libvips42 libvips-dev libvips-tools libffi-dev && composer require jcupitt/vips',
                'notes' => '1) System libvips (+ vips CLI). 2) composer require jcupitt/vips. '
                    . '3) ffi.enable=true and zend.max_allowed_stack_size=-1 (PHP 8.3+) in FPM php.ini, restart FPM. '
                    . '4) Under FPM set SUPER_IMAGES_VIPS_BINARY if needed. See docs/drivers.md#libvips.',
            ],
            default => null,
        };
    }

    /**
     * Return an install hint for a missing optimizer binary.
     *
     * @param string $tool Optimizer tool name (jpegoptim, cwebp, avifenc, etc.).
     *
     * @return array{package: string, command: string, notes: string}|null Hint array, or null for unknown tools.
     */
    public static function forBinary(string $tool): ?array
    {
        return match (strtolower($tool)) {
            'jpegoptim' => [
                'package' => 'jpegoptim',
                'command' => 'sudo apt-get install -y jpegoptim',
                'notes' => 'JPEG post-encode optimizer.',
            ],
            'oxipng' => [
                'package' => 'oxipng (not always in apt)',
                'command' => 'sudo apt-get install -y cargo && cargo install oxipng',
                'notes' => 'If cargo is unavailable, use optipng instead: sudo apt-get install -y optipng',
            ],
            'optipng' => [
                'package' => 'optipng',
                'command' => 'sudo apt-get install -y optipng',
                'notes' => 'PNG optimizer alternative to oxipng.',
            ],
            'pngquant' => [
                'package' => 'pngquant',
                'command' => 'sudo apt-get install -y pngquant',
                'notes' => 'Lossy PNG quantizer.',
            ],
            'cwebp' => [
                'package' => 'webp',
                'command' => 'sudo apt-get install -y webp',
                'notes' => 'Provides cwebp / dwebp from libwebp.',
            ],
            'avifenc' => [
                'package' => 'libavif-bin',
                'command' => 'sudo apt-get install -y libavif-bin',
                'notes' => 'Provides avifenc for AVIF encoding/optimization.',
            ],
            default => null,
        };
    }
}
