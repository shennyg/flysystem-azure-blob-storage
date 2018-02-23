<?php

namespace League\Flysystem\AzureBlobStorage;

use function array_merge;
use function compact;
use GuzzleHttp\Exception\ServerException;
use function is_string;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\BlobPrefix;
use MicrosoftAzure\Storage\Blob\Models\BlobProperties;
use MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use function stream_get_contents;

class AzureBlobStorageAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string[]
     */
    protected static $metaOptions = [
        'CacheControl',
        'ContentType',
        'Metadata',
        'ContentLanguage',
        'ContentEncoding',
    ];

    /**
     * @var BlobRestProxy
     */
    private $client;

    private $container;

    public function __construct(BlobRestProxy $client, $container, $prefix = null)
    {
        $this->client = $client;
        $this->container = $container;
        $this->setPathPrefix($prefix);
    }

    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    protected function upload($path, $contents, Config $config)
    {
        $destination = $this->applyPathPrefix($path);
        $response = $this->client->createBlockBlob(
            $this->container,
            $destination,
            $contents,
            $this->getOptionsFromConfig($config)
        );

        return [
            'path' => $path,
            'timestamp' => (int) $response->getLastModified()->getTimestamp(),
            'dirname' => Util::dirname($path),
            'type' => 'file',
        ] + (is_string($contents) ? compact('contents') : []);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    public function rename($path, $newpath)
    {
        return $this->copy($path, $newpath) && $this->delete($path);
    }

    public function copy($path, $newpath)
    {
        $source = $this->applyPathPrefix($path);
        $destination = $this->applyPathPrefix($newpath);
        $this->client->copyBlob($this->container, $source, $this->container, $destination);

        return true;
    }

    public function delete($path)
    {
        try {
            $this->client->deleteBlob($this->container, $this->applyPathPrefix($path));
        } catch (ServerException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }

        return true;
    }

    public function deleteDir($dirname)
    {
        $prefix = $this->applyPathPrefix($dirname);
        $options = new ListBlobsOptions();
        $options->setPrefix($prefix . '/');
        $listResults = $this->client->listBlobs($this->container, $options);
        foreach ($listResults->getBlobs() as $blob) {
            $this->client->deleteBlob($this->container, $blob->getName());
        }

        return true;
    }

    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname, 'type' => 'dir'];
    }

    public function has($path)
    {
        return $this->getMetadata($path);
    }

    public function read($path)
    {
        $response = $this->readStream($path);

        if ( ! isset($response['stream']) || ! is_resource($response['stream'])) {
            return $response;
        }

        $response['contents'] = stream_get_contents($response['stream']);
        unset($response['stream']);

        return $response;
    }

    public function readStream($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->client->getBlob(
                $this->container,
                $location
            );

            return $this->normalizeBlobProperties($path, $response->getProperties())
                + ['stream' => $response->getContentStream()];
        } catch (ServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            return false;
        }
    }

    public function listContents($directory = '', $recursive = false)
    {
        $location = $this->applyPathPrefix($directory);

        if (strlen($location) > 0) {
            $location = rtrim($location, '/') . '/';
        }

        $options = new ListBlobsOptions();
        $options->setPrefix($location);

        if ( ! $recursive) {
            $options->setDelimiter('/');
        }

        $result = [];
        $response =  $this->client->listBlobs($this->container, $options);
        foreach ($response->getBlobs() as $blob) {
            $result[] = $this->normalizeBlobProperties($blob->getName(), $blob->getProperties());
        }

        if ( ! $recursive) {
            $result = array_merge($result, array_map([$this, 'normalizeBlobPrefix'], $response->getBlobPrefixes()));
        }

        return Util::emulateDirectories($result);
    }

    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            return $this->normalizeBlobProperties(
                $path,
                $this->client->getBlobProperties($this->container, $path)->getProperties()
            );
        } catch (ServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            return false;
        }
    }

    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    protected function getOptionsFromConfig(Config $config)
    {
        $options = new CreateBlockBlobOptions();
        foreach (static::$metaOptions as $option) {
            if ( ! $config->has($option)) {
                continue;
            }
            call_user_func([$options, "set$option"], $config->get($option));
        }
        if ($mimetype = $config->get('mimetype')) {
            $options->setContentType($mimetype);
        }
        return $options;
    }

    protected function normalizeBlobProperties($path, BlobProperties $properties)
    {
        $path = $this->removePathPrefix($path);

        if (substr($path, -1) === '/') {
            return ['type' => 'dir', 'path' => rtrim($path, '/')];
        }
        return [
            'path' => $path,
            'timestamp' => (int) $properties->getLastModified()->format('U'),
            'dirname' => Util::dirname($path),
            'mimetype' => $properties->getContentType(),
            'size' => $properties->getContentLength(),
            'type' => 'file',
        ];
    }

    protected function normalizeBlobPrefix(BlobPrefix $blobPrefix)
    {
        return ['type' => 'dir', 'path' => $this->removePathPrefix(rtrim($blobPrefix->getName(), '/'))];
    }
}