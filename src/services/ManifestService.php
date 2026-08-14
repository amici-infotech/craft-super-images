<?php

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
 * Builds side-effect-free generation manifests for assets.
 */
final class ManifestService extends Component
{
    /**
     * @param array<string, mixed> $filters profile, variant, format, fieldHandle
     * @return list<ManifestUnit>
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
     * @param array<string, array<string, mixed>> $profilesConfig
     * @return list<string>
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
     * @return list<string>
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

    private function resolveField(mixed $fieldHandle): ?FieldInterface
    {
        if (!is_string($fieldHandle) || $fieldHandle === '') {
            return null;
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);

        return $field instanceof FieldInterface ? $field : null;
    }

    /**
     * @return list<string>
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
     * @return list<string>
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
