<?php

namespace Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;

/**
 * A storage adapter with no directory on this machine behind it, like an object store: no local paths, no seeking
 * into existing files, whole objects only. Code that works on this adapter does not depend on a local filesystem.
 */
class MemoryAdapter implements FilesystemAdapter
{
    /** @var array<string,string> */
    public array $objects = [];

    public function fileExists(string $path): bool
    {
        return isset($this->objects[$path]);
    }

    public function directoryExists(string $path): bool
    {
        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, rtrim($path, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->objects[$path] = $contents;
    }

    /** Test switch: store a damaged copy, as a faulty transfer would. */
    public bool $corruptWrites = false;

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->objects[$path] = (string) stream_get_contents($contents).($this->corruptWrites ? 'x' : '');
    }

    public function read(string $path): string
    {
        return $this->objects[$path] ?? throw UnableToReadFile::fromLocation($path);
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }

    public function deleteDirectory(string $path): void
    {
        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, rtrim($path, '/').'/')) {
                unset($this->objects[$key]);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
    }

    public function setVisibility(string $path, string $visibility): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, time());
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->read($path)));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/').'/';
        $directories = [];
        foreach ($this->objects as $key => $contents) {
            if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
                continue;
            }
            $rest = substr($key, strlen($prefix));
            if (! $deep && str_contains($rest, '/')) {
                $directories[$prefix.explode('/', $rest)[0]] = true;

                continue;
            }
            yield new FileAttributes($key, strlen($contents));
        }
        foreach (array_keys($directories) as $directory) {
            yield new DirectoryAttributes($directory);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->objects[$destination] = $this->read($source);
        unset($this->objects[$source]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->objects[$destination] = $this->read($source);
    }
}
