<?php
/**
 * Resolves effective generation configuration from settings and request context.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

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

/**
 * Configuration Resolver
 *
 * Merges plugin settings, volume/folder/field scope overrides, and request parameters
 * into an EffectiveConfig and GenerationDefinition. Results are cached per request fingerprint.
 */
final class ConfigurationResolver extends Component
{
    /**
     * In-memory cache of resolved EffectiveConfig objects keyed by request hash.
     *
     * @var array<string, EffectiveConfig>
     */
    private array $_cache = [];

    /**
     * Resolve the effective configuration for a generation request.
     *
     * Applies scope overrides (volume, folder, field), resolves profile/variant/format,
     * merges operations and encoder options, and caches the result.
     *
     * @param GenerationRequest $request The generation request with optional scope context.
     *
     * @return EffectiveConfig The fully merged configuration for this request.
     *
     * @throws InvalidConfigurationException When the resolved profile is not defined.
     */
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

        $policies = is_array($settings->policies) ? $settings->policies : [];
        $geometry = is_array($policies['geometry'] ?? null) ? $policies['geometry'] : [];
        $safety = is_array($policies['safety'] ?? null) ? $policies['safety'] : [];

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
            allowUpscale: (bool)($geometry['allowUpscale'] ?? false),
            maxSourcePixels: (int)($safety['maxSourcePixels'] ?? 40_000_000),
        );

        return $this->_cache[$cacheKey] = $effective;
    }

    /**
     * Build a GenerationDefinition from resolved config and source identity.
     *
     * @param GenerationRequest $request The original generation request.
     * @param EffectiveConfig $config The resolved effective configuration.
     * @param string $sourceIdentity The stable identity string for the source image.
     * @param string $driverName The selected image driver name.
     *
     * @return GenerationDefinition The immutable definition used for identity calculation and generation.
     */
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
     * Merge volume, folder, and field scope overrides into the config layer.
     *
     * @param array<string, mixed> $layer The base configuration layer from settings.
     * @param GenerationRequest $request The request carrying optional volume, folder, and field context.
     *
     * @return array<string, mixed> The merged configuration layer.
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

    /**
     * Resolve a VariantDefinition from global variants, profile variants, or transforms.
     *
     * @param Settings $settings Plugin settings containing global variant definitions.
     * @param ProfileDefinition $profile The active profile definition.
     * @param string $variantName The requested variant name or numeric transform index.
     * @param GenerationRequest $request The generation request (unused; reserved for future overrides).
     *
     * @return VariantDefinition The resolved variant with merged profile defaults.
     */
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
     * @param list<OperationDefinition> $operations The geometry operations from the variant.
     * @param array<string, mixed> $defaults Profile-level default effect settings.
     *
     * @return list<OperationDefinition> The operations list with effect defaults appended.
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
     * Resolve encoder options for a format, applying profile quality overrides.
     *
     * @param Settings $settings Plugin settings containing encoder configuration.
     * @param string $format The output format (e.g. webp, jpg).
     * @param ProfileDefinition $profile The active profile definition.
     *
     * @return array<string, mixed> The merged encoder options for this format.
     */
    private function resolveEncoderOptions(Settings $settings, string $format, ProfileDefinition $profile): array
    {
        $encodePolicy = $settings->policies['encode'] ?? [];
        $options = is_array($encodePolicy) ? $encodePolicy : [];

        $formatKey = $format === 'jpg' ? 'jpeg' : $format;
        $formatOptions = $settings->encoders[$formatKey] ?? $settings->encoders[$format] ?? [];
        if (is_array($formatOptions)) {
            $options = array_merge($options, $formatOptions);
        }

        if (isset($profile->defaults['jpegQuality']) && in_array($format, ['jpg', 'jpeg'], true)) {
            $options['quality'] = (int)$profile->defaults['jpegQuality'];
        }

        return $options;
    }

    /**
     * Build a cache key fingerprint for a generation request and settings snapshot.
     *
     * @param GenerationRequest $request The generation request.
     * @param Settings $settings The current plugin settings.
     *
     * @return string An MD5 hash of the request and config fingerprint.
     */
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
