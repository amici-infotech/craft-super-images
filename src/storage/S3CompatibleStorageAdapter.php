<?php
/**
 * S3-compatible remote storage adapter backed by the AWS SDK.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\storage;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\exceptions\StorageConfigurationException;
use amici\SuperImages\exceptions\StorageException;
use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;

/**
 * S3 Compatible Storage Adapter
 *
 * Uploads derivatives to an S3 or S3-compatible bucket with optional key prefix and custom endpoint.
 */
final class S3CompatibleStorageAdapter implements StorageAdapterInterface
{
    /** @var object AWS S3 client instance. */
    private object $_client;

    /** @var string Target bucket name. */
    private string $_bucket;

    /** @var string Optional key prefix applied to all object paths. */
    private string $_prefix;

    /** @var string Optional CDN or custom base URL; empty when using SDK object URLs. */
    private string $_baseUrl;

    /**
     * @param string $adapterName Logical adapter name from configuration.
     * @param array<string, mixed> $config Bucket, credentials, region, endpoint, and URL settings.
     *
     * @throws StorageConfigurationException When the AWS SDK is missing or bucket is not configured.
     */
    public function __construct(
        private string $adapterName,
        array $config,
    ) {
        if (!class_exists('Aws\\S3\\S3Client')) {
            throw new StorageConfigurationException(
                'AWS SDK is required for S3-compatible storage. Install aws/aws-sdk-php.',
            );
        }

        $this->_bucket = (string) ($config['bucket'] ?? '');
        $this->_prefix = trim((string) ($config['prefix'] ?? ''), '/');
        $this->_baseUrl = rtrim((string) ($config['baseUrl'] ?? ''), '/');

        if ($this->_bucket === '') {
            throw new StorageConfigurationException('S3 bucket is required.');
        }

        $clientConfig = [
            'version' => 'latest',
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'credentials' => [
                'key' => (string) ($config['keyId'] ?? ''),
                'secret' => (string) ($config['secret'] ?? ''),
            ],
        ];

        if (!empty($config['endpoint'])) {
            $clientConfig['endpoint'] = (string) $config['endpoint'];
            $clientConfig['use_path_style_endpoint'] = (bool) ($config['usePathStyle'] ?? $config['pathStyleEndpoint'] ?? true);
        }

        $clientClass = 'Aws\\S3\\S3Client';
        $this->_client = new $clientClass($clientConfig);
    }

    /**
     * Returns the configured adapter name.
     *
     * @return string The logical adapter identifier.
     */
    public function name(): string
    {
        return $this->adapterName;
    }

    /**
     * Uploads binary contents to the bucket at the given relative path.
     *
     * @param string $path Relative storage path.
     * @param string $contents Raw file bytes to upload.
     * @param StorageWriteOptions $options ACL, content type, and custom metadata.
     *
     * @return StorageObject Metadata for the stored object including its public URL.
     */
    public function write(string $path, string $contents, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        $key = $this->key($path);

        $this->_client->putObject([
            'Bucket' => $this->_bucket,
            'Key' => $key,
            'Body' => $contents,
            'ACL' => $options->public ? 'public-read' : 'private',
            'ContentType' => $options->contentType,
            'Metadata' => $options->metadata,
        ]);

        return new StorageObject(
            $path,
            $this->url($path),
            strlen($contents),
            $options->contentType,
        );
    }

    /**
     * Uploads a local file to the bucket at the given relative path.
     *
     * @param string $path Relative storage path for the destination object.
     * @param string $localFile Absolute path to the readable source file.
     * @param StorageWriteOptions $options ACL, content type, and custom metadata.
     *
     * @return StorageObject Metadata for the stored object including its public URL.
     *
     * @throws StorageException When the source file is not readable.
     */
    public function writeFile(string $path, string $localFile, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        if (!is_readable($localFile)) {
            throw new StorageException('Local source file is not readable.');
        }

        $key = $this->key($path);

        $this->_client->putObject([
            'Bucket' => $this->_bucket,
            'Key' => $key,
            'SourceFile' => $localFile,
            'ACL' => $options->public ? 'public-read' : 'private',
            'ContentType' => $options->contentType,
            'Metadata' => $options->metadata,
        ]);

        return new StorageObject(
            $path,
            $this->url($path),
            (int)filesize($localFile),
            $options->contentType,
        );
    }

    /**
     * Checks whether an object exists in the bucket.
     *
     * @param string $path Relative storage path.
     *
     * @return bool True when the object key exists in the configured bucket.
     */
    public function exists(string $path): bool
    {
        return $this->_client->doesObjectExist($this->_bucket, $this->key($path));
    }

    /**
     * Deletes the object at the given relative path from the bucket.
     *
     * @param string $path Relative storage path.
     *
     * @return void
     */
    public function delete(string $path): void
    {
        $this->_client->deleteObject([
            'Bucket' => $this->_bucket,
            'Key' => $this->key($path),
        ]);
    }

    /**
     * Builds the public URL for a stored object.
     *
     * Uses the configured base URL when set; otherwise falls back to the SDK object URL.
     *
     * @param string $path Relative storage path.
     *
     * @return string Public URL for the object.
     */
    public function url(string $path): string
    {
        if ($this->_baseUrl !== '') {
            return $this->_baseUrl . '/' . ltrim(str_replace('\\', '/', $path), '/');
        }

        return (string)$this->_client->getObjectUrl($this->_bucket, $this->key($path));
    }

    /**
     * Describes remote S3 storage capabilities for the pipeline.
     *
     * @return StorageCapabilities Capability flags for this adapter.
     */
    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(
            remote: true,
            publicUrls: true,
            atomicWrite: false,
        );
    }

    /**
     * Builds the full S3 object key from a relative storage path and optional prefix.
     *
     * @param string $path Relative storage path.
     *
     * @return string Object key sent to S3 APIs.
     */
    private function key(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($this->_prefix === '') {
            return $path;
        }

        return $this->_prefix . '/' . $path;
    }
}
