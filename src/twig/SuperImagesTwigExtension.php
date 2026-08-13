<?php
/**
 * Twig filters for Super Images (Phase 1 test helpers).
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\twig;

use amici\SuperImages\variables\SuperImagesVariable;
use craft\elements\Asset;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

/**
 * Super Images Twig Extension
 */
class SuperImagesTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('generateUrl', [$this, 'generateUrl']),
            new TwigFilter('generateImgTag', [$this, 'generateImgTag'], ['is_safe' => ['html']]),
            new TwigFilter('generatePictureTag', [$this, 'generatePictureTag'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param Asset|string|int $source
     * @param string|array<string, mixed>|null $formatOrOptions
     * @param array<string, mixed> $options
     */
    public function generateUrl(Asset|string|int $source, string|array|null $formatOrOptions = null, array $options = []): string
    {
        return $this->variable()->url($source, $this->normalizeOptions($formatOrOptions, $options));
    }

    /**
     * @param Asset|string|int $source
     * @param string|array<string, mixed>|null $formatOrOptions
     * @param array<string, mixed> $options
     */
    public function generateImgTag(Asset|string|int $source, string|array|null $formatOrOptions = null, array $options = []): Markup
    {
        return $this->variable()->img($source, $this->normalizeOptions($formatOrOptions, $options));
    }

    /**
     * @param Asset|string|int $source
     * @param array<string, mixed>|list<string>|null $formatsOrOptions
     * @param array<string, mixed> $options
     */
    public function generatePictureTag(Asset|string|int $source, array|null $formatsOrOptions = null, array $options = []): Markup
    {
        if ($formatsOrOptions !== null && array_is_list($formatsOrOptions)) {
            $options['formats'] = $formatsOrOptions;
        } elseif (is_array($formatsOrOptions)) {
            $options = array_merge($formatsOrOptions, $options);
        }

        return $this->variable()->picture($source, $options);
    }

    /**
     * @param string|array<string, mixed>|null $formatOrOptions
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(string|array|null $formatOrOptions, array $options): array
    {
        if (is_string($formatOrOptions) && $formatOrOptions !== '') {
            $options['format'] = $formatOrOptions;
        } elseif (is_array($formatOrOptions)) {
            $options = array_merge($formatOrOptions, $options);
        }

        return $options;
    }

    private function variable(): SuperImagesVariable
    {
        return new SuperImagesVariable();
    }
}
