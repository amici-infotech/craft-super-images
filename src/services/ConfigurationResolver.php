<?php

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\InvalidConfigurationException;
use amici\SuperImages\models\EffectiveConfig;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\GenerationDefinition;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\models\ProfileDefinition;
use amici\SuperImages\models\Settings;
use amici\SuperImages\models\VariantDefinition;
use amici\SuperImages\Plugin;
use craft\base\FieldInterface;
use craft\models\Volume;
use craft\models\VolumeFolder;
use yii\base\Component;

final class ConfigurationResolver extends Component
{
    /** @var array<string, EffectiveConfig> */
    private array $_cache = [];

    public function resolve(GenerationRequest $request): EffectiveConfig
    {
        $settings = Plugin::getInstance()->getSettings();
        $cacheKey = $this->cacheKey($request, $settings);

        if (isset($this->_cache[$cacheKey])) {
            return $this->_cache[$cacheKey];
        }

        $layer = $settings->getConfig();
        $layer = $this->applyScopeOverrides($layer, $request);

        $profileName = $request->profile
            ?? ($layer['profile'] ?? null)
            ?? $settings->defaultProfile;

        $profileConfig = $settings->profiles[$profileName] ?? null;
        if ($profileConfig === null) {
            throw new InvalidConfigurationException(sprintf('Profile "%s" is not defined.', $profileName));
        }

        $profile = ProfileDefinition::fromArray($profileName, $profileConfig);

        $variantName = $request->variant
            ?? (array_key_first($profile->variants) !== null ? (string) array_key_first($profile->variants) : 'default');
        $format = strtolower($request->format ?? $settings->defaultFormat);

        $variant = $this->resolveVariant($settings, $profile, $variantName, $request);

        $operations = $this->mergeProfileDefaultOperations($variant->toOperations(), $profile->defaults);

        if ($request->operationOverrides !== null) {
            $operations = [];
            foreach ($request->operationOverrides as $op) {
                $operations[] = $op instanceof OperationDefinition
                    ? $op
                    : OperationDefinition::fromArray(is_array($op) ? $op : []);
            }
        }

        $encoderOptions = $this->resolveEncoderOptions($settings, $format, $profile);
        $optimizerOptions = $settings->optimizers;
        $storageAdapter = $request->storageAdapter ?? null;
        if ($storageAdapter === null && isset($layer['storage']) && is_string($layer['storage'])) {
            $storageAdapter = $layer['storage'];
        }
        if ($storageAdapter === null) {
            $storageAdapter = $settings->storage['default'] ?? 'local';
        }

        $effective = new EffectiveConfig(
            driver: (string)($layer['driver'] ?? $settings->driver),
            profile: $profileName,
            variant: $variantName,
            format: $format,
            formats: $profile->formats !== [] ? $profile->formats : [$format],
            operations: $operations,
            encoderOptions: $encoderOptions,
            optimizerOptions: $optimizerOptions,
            storageAdapter: (string)$storageAdapter,
            storageConfig: $settings->storage,
            runtime: $settings->runtime,
            optimizersEnabled: (bool)($optimizerOptions['enabled'] ?? true),
        );

        return $this->_cache[$cacheKey] = $effective;
    }

    public function buildDefinition(
        GenerationRequest $request,
        EffectiveConfig $config,
        string $sourceIdentity,
        string $driverName,
    ): GenerationDefinition {
        $quality = isset($config->encoderOptions['quality']) ? (int)$config->encoderOptions['quality'] : null;

        return new GenerationDefinition(
            sourceIdentity: $sourceIdentity,
            profile: $config->profile,
            variant: $config->variant,
            format: $config->format,
            operations: $config->operations,
            encodeOptions: new EncodeOptions(
                quality: $quality,
                stripMetadata: (bool)($config->encoderOptions['stripMetadata'] ?? true),
                extra: array_diff_key($config->encoderOptions, array_flip(['quality', 'stripMetadata'])),
            ),
            optimizerOptions: $config->optimizerOptions,
            driverPreference: $config->driver,
            storageAdapter: $config->storageAdapter,
            schemaVersion: Settings::SCHEMA_VERSION,
        );
    }

    /**
     * @param array<string, mixed> $layer
     * @return array<string, mixed>
     */
    private function applyScopeOverrides(array $layer, GenerationRequest $request): array
    {
        if ($request->volume instanceof Volume) {
            $volumeHandle = $request->volume->handle;
            $layer = array_merge($layer, $layer['volumes'][$volumeHandle] ?? []);
        }

        if ($request->folder instanceof VolumeFolder) {
            $folderPath = trim($request->folder->path, '/');
            $layer = array_merge($layer, $layer['folders'][$folderPath] ?? []);
        }

        if ($request->field instanceof FieldInterface) {
            $layer = array_merge($layer, $layer['fields'][$request->field->handle] ?? []);
        }

        return $layer;
    }

    private function resolveVariant(
        Settings $settings,
        ProfileDefinition $profile,
        string $variantName,
        GenerationRequest $request,
    ): VariantDefinition {
        if (isset($settings->variants[$variantName]) && is_array($settings->variants[$variantName])) {
            return VariantDefinition::fromArray(
                $variantName,
                array_merge($profile->defaults, $settings->variants[$variantName]),
            );
        }

        if (isset($profile->variants[$variantName]) && is_array($profile->variants[$variantName])) {
            return VariantDefinition::fromArray(
                $variantName,
                array_merge($profile->defaults, $profile->variants[$variantName]),
            );
        }

        if ($variantName === 'default' && $profile->transforms !== []) {
            $first = $profile->transforms[0];

            return VariantDefinition::fromArray(
                'default',
                array_merge($profile->defaults, is_array($first) ? $first : []),
            );
        }

        foreach ($profile->transforms as $index => $transform) {
            if (!is_array($transform)) {
                continue;
            }

            $name = is_string($transform['name'] ?? null) ? $transform['name'] : (string) $index;
            if ($name === $variantName) {
                return VariantDefinition::fromArray(
                    $variantName,
                    array_merge($profile->defaults, $transform),
                );
            }
        }

        // Allow numeric-string index into transforms list (e.g. "0", "1").
        if (ctype_digit($variantName) && isset($profile->transforms[(int) $variantName])) {
            $transform = $profile->transforms[(int) $variantName];

            return VariantDefinition::fromArray(
                $variantName,
                array_merge($profile->defaults, is_array($transform) ? $transform : []),
            );
        }

        return VariantDefinition::fromArray($variantName, $profile->defaults);
    }

    /**
     * Append profile-level effect defaults (e.g. sharpen) after geometry ops.
     *
     * @param list<OperationDefinition> $operations
     * @param array<string, mixed> $defaults
     * @return list<OperationDefinition>
     */
    private function mergeProfileDefaultOperations(array $operations, array $defaults): array
    {
        foreach (['sharpen', 'blur', 'brightness', 'contrast', 'grayscale', 'sepia', 'invert'] as $effect) {
            if (!array_key_exists($effect, $defaults)) {
                continue;
            }

            $value = $defaults[$effect];
            if ($value === false || $value === null) {
                continue;
            }

            $options = is_array($value) ? $value : ['amount' => $value];
            if ($effect === 'grayscale' || $effect === 'invert') {
                $options = ['enabled' => (bool) $value];
            }

            $operations[] = new OperationDefinition($effect, OperationDefinition::normalizeOptions($options));
        }

        if (isset($defaults['watermark'])) {
            $wm = $defaults['watermark'];
            $operations[] = new OperationDefinition(
                'watermark',
                OperationDefinition::normalizeOptions(is_array($wm) ? $wm : ['source' => $wm]),
            );
        }

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEncoderOptions(Settings $settings, string $format, ProfileDefinition $profile): array
    {
        $formatKey = $format === 'jpg' ? 'jpeg' : $format;
        $options = $settings->encoders[$formatKey] ?? $settings->encoders[$format] ?? [];

        if (isset($profile->defaults['jpegQuality']) && in_array($format, ['jpg', 'jpeg'], true)) {
            $options['quality'] = (int)$profile->defaults['jpegQuality'];
        }

        return is_array($options) ? $options : [];
    }

    private function cacheKey(GenerationRequest $request, Settings $settings): string
    {
        return md5(json_encode([
            $request->assetId,
            $request->localPath,
            $request->remoteUrl,
            $request->profile,
            $request->variant,
            $request->format,
            $request->operationOverrides,
            $request->volume?->id,
            $request->folder?->id,
            $request->field?->id,
            $settings->getConfig(),
        ], JSON_THROW_ON_ERROR));
    }
}
