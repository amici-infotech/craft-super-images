<?php

namespace amici\SuperImages\support;

/**
 * Ubuntu apt install hints for missing drivers and optimizer binaries.
 *
 * Live servers are assumed to be Ubuntu; hints are intentionally apt-focused.
 */
final class UbuntuInstallHints
{
    /**
     * @return array{package: string, command: string, notes: string}|null
     */
    public static function forDriver(string $driver): ?array
    {
        return match (strtolower($driver)) {
            'gd' => [
                'package' => 'php-gd',
                'command' => 'sudo apt-get install -y php-gd',
                'notes' => 'Use the PHP-versioned package if needed (e.g. php8.3-gd), then restart PHP-FPM.',
            ],
            'imagick' => [
                'package' => 'php-imagick',
                'command' => 'sudo apt-get install -y php-imagick',
                'notes' => 'Use the PHP-versioned package if needed (e.g. php8.3-imagick), then restart PHP-FPM.',
            ],
            'libvips' => [
                'package' => 'libvips42 / libvips-dev',
                'command' => 'sudo apt-get install -y libvips42 libvips-dev && composer require jcupitt/vips',
                'notes' => 'Install system libs first, then the PHP binding package.',
            ],
            default => null,
        };
    }

    /**
     * @return array{package: string, command: string, notes: string}|null
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
