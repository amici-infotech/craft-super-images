<?php

namespace amici\SuperImages\storage;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\exceptions\StorageConfigurationException;
use amici\SuperImages\exceptions\StorageException;
use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;

final class S3CompatibleStorageAdapter implements StorageAdapterInterface
{
    private object $_client;
    private string $_bucket;
    private string $_prefix;
    private string $_baseUrl;

    /**
     * @param array<string, mixed> $config
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

    public function name(): string
    {
        return $this->adapterName;
    }

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

    public function exists(string $path): bool
    {
        return $this->_client->doesObjectExist($this->_bucket, $this->key($path));
    }

    public function delete(string $path): void
    {
        $this->_client->deleteObject([
            'Bucket' => $this->_bucket,
            'Key' => $this->key($path),
        ]);
    }

    public function url(string $path): string
    {
        if ($this->_baseUrl !== '') {
            return $this->_baseUrl . '/' . ltrim(str_replace('\\', '/', $path), '/');
        }

        return (string)$this->_client->getObjectUrl($this->_bucket, $this->key($path));
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(
            remote: true,
            publicUrls: true,
            atomicWrite: false,
        );
    }

    private function key(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($this->_prefix === '') {
            return $path;
        }

        return $this->_prefix . '/' . $path;
    }
}
