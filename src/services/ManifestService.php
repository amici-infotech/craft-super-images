<?php
/**
 * Builds side-effect-free generation manifests for assets.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\InvalidConfigurationException;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\ManifestUnit;
use amici\SuperImages\models\ProfileDefinition;
use amici\SuperImages\Plugin;
use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use yii\base\Component;

/**
 * Manifest Service
 *
 * Plans all derivative units for an asset (profile × variant × format) without
 * performing generation or storage I/O. Used by CLI, queue jobs, and auto-generate.
 */
final class ManifestService extends Component
{
    /**
     * Build the full manifest of planned derivatives for an asset.
     *
     * @param Asset $asset The source asset element.
     * @param array<string, mixed> $filters Optional filters: profile, variant, format, fieldHandle.
     *
     * @return list<ManifestUnit> Deduplicated list of planned generation units.
     *
     * @throws InvalidConfigurationException When the resolved default profile is not defined.
     */
    public function buildForAsset(Asset $asset, array $filters = []): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $field = $this->resolveField($filters['fieldHandle'] ?? null);
        $profiles = $this->resolveProfiles($asset, $settings->profiles, $filters, $field);

        $units = [];
        $seen = [];

        foreach ($profiles as $profileName) {
            $profileConfig = $settings->profiles[$profileName] ?? null;
            if (!is_array($profileConfig)) {
                continue;
            }

            $profile = ProfileDefinition::fromArray($profileName, $profileConfig);
            $variants = $this->resolveVariantNames($profile, $filters['variant'] ?? null);
            $formats = $this->resolveFormats($profile, $filters['format'] ?? null);

            foreach ($variants as $variantName) {
                foreach ($formats as $format) {
                    $request = new GenerationRequest(
                        assetId: (int) $asset->id,
                        profile: $profileName,
                        variant: $variantName,
                        format: $format,
                        volume: $asset->getVolume(),
                        folder: $asset->getFolder(),
                        field: $field,
                    );

                    $planned = $plugin->getGeneration()->plan($request);
                    $dedupeKey = $planned['identity'] . '|' . $planned['storagePath'];

                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }

                    $seen[$dedupeKey] = true;
                    $units[] = ManifestUnit::fromAsset(
                        $asset,
                        $profileName,
                        $variantName,
                        $format,
                        $planned['identity'],
                        $planned['storagePath'],
                        $planned['storageUrl'],
                        $planned['driverName'],
                    );
                }
            }
        }

        return $units;
    }

    /**
     * Count planned units for an asset without running full identity/path planning.
     *
     * Used by CLI progress totals so startup is O(profiles×variants×formats), not a
     * full `plan()` pass over every derivative in the volume.
     *
     * @param Asset $asset The source asset element.
     * @param array<string, mixed> $filters Optional filters: profile, variant, format, fieldHandle.
     *
     * @return int Number of variant×format units that would be generated.
     */
    public function countUnitsForAsset(Asset $asset, array $filters = []): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $field = $this->resolveField($filters['fieldHandle'] ?? null);
        $profiles = $this->resolveProfiles($asset, $settings->profiles, $filters, $field);
        $total = 0;

        foreach ($profiles as $profileName) {
            $profileConfig = $settings->profiles[$profileName] ?? null;
            if (!is_array($profileConfig)) {
                continue;
            }

            $profile = ProfileDefinition::fromArray($profileName, $profileConfig);
            $variants = $this->resolveVariantNames($profile, $filters['variant'] ?? null);
            $formats = $this->resolveFormats($profile, $filters['format'] ?? null);
            $total += count($variants) * count($formats);
        }

        return $total;
    }

    /**
     * Estimate units-per-asset from volume/default profile config (no asset DB work).
     *
     * @param string|null $volumeHandle Optional volume handle for volume-scoped profiles.
     * @param array<string, mixed> $filters Optional profile/variant/format filters.
     *
     * @return int Estimated units per asset for progress display.
     */
    public function estimateUnitsPerAsset(?string $volumeHandle, array $filters = []): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $profiles = [];

        if (!empty($filters['profile']) && is_string($filters['profile'])) {
            $profiles = [(string) $filters['profile']];
        } elseif ($volumeHandle !== null && $volumeHandle !== '') {
            $volumeConfig = $settings->volumes[$volumeHandle] ?? [];
            if (!empty($volumeConfig['profile']) && is_string($volumeConfig['profile'])) {
                $profiles = [(string) $volumeConfig['profile']];
            } elseif (!empty($volumeConfig['profiles']) && is_array($volumeConfig['profiles'])) {
                $profiles = array_values(array_map('strval', $volumeConfig['profiles']));
            }
        }

        if ($profiles === []) {
            $profiles = [$settings->defaultProfile];
        }

        $total = 0;
        foreach ($profiles as $profileName) {
            $profileConfig = $settings->profiles[$profileName] ?? null;
            if (!is_array($profileConfig)) {
                continue;
            }

            $profile = ProfileDefinition::fromArray($profileName, $profileConfig);
            $variants = $this->resolveVariantNames($profile, $filters['variant'] ?? null);
            $formats = $this->resolveFormats($profile, $filters['format'] ?? null);
            $total += count($variants) * count($formats);
        }

        return max(1, $total);
    }

    /**
     * Resolve which profiles apply to an asset from filters, field, volume, or default.
     *
     * @param Asset $asset The source asset element.
     * @param array<string, array<string, mixed>> $profilesConfig All profile definitions from settings.
     * @param array<string, mixed> $filters Request filters that may include profile.
     * @param FieldInterface|null $field Optional field context for field-level profile rules.
     *
     * @return list<string> Profile handles to include in the manifest.
     *
     * @throws InvalidConfigurationException When the default profile is not defined.
     */
    private function resolveProfiles(
        Asset $asset,
        array $profilesConfig,
        array $filters,
        ?FieldInterface $field,
    ): array {
        if (!empty($filters['profile']) && is_string($filters['profile'])) {
            return [(string) $filters['profile']];
        }

        if ($field !== null) {
            $fieldProfiles = $this->fieldProfiles($field);
            if ($fieldProfiles !== []) {
                return $fieldProfiles;
            }
        }

        $volumeHandle = $asset->getVolume()->handle;
        $volumeConfig = Plugin::getInstance()->getSettings()->volumes[$volumeHandle] ?? [];

        if (!empty($volumeConfig['profile']) && is_string($volumeConfig['profile'])) {
            return [(string) $volumeConfig['profile']];
        }

        if (!empty($volumeConfig['profiles']) && is_array($volumeConfig['profiles'])) {
            return array_values(array_map('strval', $volumeConfig['profiles']));
        }

        $default = Plugin::getInstance()->getSettings()->defaultProfile;

        if (!isset($profilesConfig[$default])) {
            throw new InvalidConfigurationException(sprintf('Default profile "%s" is not defined.', $default));
        }

        return [$default];
    }

    /**
     * Resolve profile handles configured for a specific field.
     *
     * @param FieldInterface $field The Craft field element.
     *
     * @return list<string> Profile handles from field config, or empty when none are set.
     */
    private function fieldProfiles(FieldInterface $field): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $fieldConfig = $settings->fields[$field->handle] ?? [];

        if (!empty($fieldConfig['profiles']) && is_array($fieldConfig['profiles'])) {
            return array_values(array_map('strval', $fieldConfig['profiles']));
        }

        if (!empty($fieldConfig['profile']) && is_string($fieldConfig['profile'])) {
            return [(string) $fieldConfig['profile']];
        }

        return [];
    }

    /**
     * Resolve a field element from an optional handle filter.
     *
     * @param mixed $fieldHandle Field handle from filters, or null.
     *
     * @return FieldInterface|null The field element, or null when handle is missing or invalid.
     */
    private function resolveField(mixed $fieldHandle): ?FieldInterface
    {
        if (!is_string($fieldHandle) || $fieldHandle === '') {
            return null;
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);

        return $field instanceof FieldInterface ? $field : null;
    }

    /**
     * Resolve variant names from a filter or profile definition.
     *
     * @param ProfileDefinition $profile The active profile definition.
     * @param mixed $filter Optional variant name filter.
     *
     * @return list<string> Variant names to include in the manifest.
     */
    private function resolveVariantNames(ProfileDefinition $profile, mixed $filter): array
    {
        if (is_string($filter) && $filter !== '') {
            return [$filter];
        }

        if ($profile->variants !== []) {
            return array_keys($profile->variants);
        }

        if ($profile->transforms !== []) {
            $names = [];
            foreach ($profile->transforms as $index => $transform) {
                if (!is_array($transform)) {
                    continue;
                }

                $names[] = is_string($transform['name'] ?? null)
                    ? $transform['name']
                    : (string) $index;
            }

            if ($names !== []) {
                return $names;
            }
        }

        return ['default'];
    }

    /**
     * Resolve output formats from a filter or profile definition.
     *
     * @param ProfileDefinition $profile The active profile definition.
     * @param mixed $filter Optional format filter.
     *
     * @return list<string> Format strings to include in the manifest.
     */
    private function resolveFormats(ProfileDefinition $profile, mixed $filter): array
    {
        if (is_string($filter) && $filter !== '') {
            return [$filter];
        }

        if ($profile->formats !== []) {
            return $profile->formats;
        }

        return [Plugin::getInstance()->getSettings()->defaultFormat];
    }
}
