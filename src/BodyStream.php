<?php


namespace WPRestApi\PSR7;

use Psr\Http\Message\StreamInterface;

class BodyStream implements StreamInterface
{
    private mixed $stream;
    private mixed $size;
    private bool $seekable;
    private bool $readable;
    private bool $writable;
    private mixed $uri;
    private mixed $customMetadata;

    /** @var array Hash of readable and writable stream types */
    private static $readWriteHash = [
        'read' => [
            'r' => true,
            'w+' => true,
            'r+' => true,
            'x+' => true,
            'c+' => true,
            'rb' => true,
            'w+b' => true,
            'r+b' => true,
            'x+b' => true,
            'c+b' => true,
            'rt' => true,
            'w+t' => true,
            'r+t' => true,
            'x+t' => true,
            'c+t' => true,
            'a+' => true
        ],
        'write' => [
            'w' => true,
            'w+' => true,
            'rw' => true,
            'r+' => true,
            'x+' => true,
            'c+' => true,
            'wb' => true,
            'w+b' => true,
            'r+b' => true,
            'x+b' => true,
            'c+b' => true,
            'w+t' => true,
            'r+t' => true,
            'x+t' => true,
            'c+t' => true,
            'a' => true,
            'a+' => true
        ]
    ];

    /**
     * This constructor accepts an associative array of options.
     *
     * - size: (int) If a read stream would otherwise have an indeterminate
     *   size, but the size is known due to foreknowledge, then you can
     *   provide that size, in bytes.
     * - metadata: (array) Any additional metadata to return when the metadata
     *   of the stream is accessed.
     *
     * @param resource $stream  Stream resource to wrap.
     * @param array    $options Associative array of options.
     *
     * @throws \InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, $options = [])
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource');
        }

        if (isset($options['size'])) {
            $this->size = $options['size'];
        }


        $this->customMetadata = isset($options['metadata'])
            ? $options['metadata']
            : [];

        $this->stream = $stream;
        $meta = stream_get_meta_data($this->stream);
        $this->seekable = $meta['seekable'];
        $this->readable = isset(self::$readWriteHash['read'][$meta['mode']]);
        $this->writable = isset(self::$readWriteHash['write'][$meta['mode']]);
        $this->uri = $this->getMetadata('uri');
    }

    public function __get(string $name): void
    {
        if ($name == 'stream') {
            throw new \RuntimeException('The stream is detached');
        }

        throw new \BadMethodCallException('No value for ' . $name);
    }

    /**
     * Closes the stream when the destructed
     */
    public function __destruct()
    {
        $this->close();
    }

    public function __toString()
    {
        try {
            $this->seek(0);
            return (string) stream_get_contents($this->stream);
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getContents(): string
    {
        $contents = stream_get_contents($this->stream);

        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    public function close(): void
    {
        if (isset($this->stream)) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->detach();
        }
    }

    public function detach()
    {
        if (!isset($this->stream)) {
            return null;
        }

        $result = $this->stream;
        unset($this->stream);
        $this->size = $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (!isset($this->stream)) {
            return null;
        }

        // Clear the stat cache if the stream has a URI
        if ($this->uri) {
            clearstatcache(true, $this->uri);
        }

        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * {@inheritDoc}
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * {@inheritDoc}
     */
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * {@inheritDoc}
     */
    public function eof(): bool
    {
        return !$this->stream || feof($this->stream);
    }

    /**
     * {@inheritDoc}
     */
    public function tell(): int
    {
        $result = ftell($this->stream);

        if ($result === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * {@inheritDoc}
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        } elseif (fseek($this->stream, $offset, $whence) === -1) {
            throw new \RuntimeException('Unable to seek to stream position '
                . $offset . ' with whence ' . var_export($whence, true));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function read($length): string
    {
        if (!$this->readable) {
            throw new \RuntimeException('Cannot read from non-readable stream');
        }
        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if (0 === $length) {
            return '';
        }

        $string = fread($this->stream, $length);
        if (false === $string) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $string;
    }

    /**
     * {@inheritDoc}
     */
    public function write($string): int
    {
        if (!$this->writable) {
            throw new \RuntimeException('Cannot write to a non-writable stream');
        }

        // We can't know the size after writing anything
        $this->size = null;
        $result = fwrite($this->stream, $string);

        if ($result === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        return $result;
    }

    public function getMetadata($key = null)
    {
        if (!isset($this->stream)) {
            return $key ? null : [];
        } elseif (!$key) {
            return $this->customMetadata + stream_get_meta_data($this->stream);
        } elseif (isset($this->customMetadata[$key])) {
            return $this->customMetadata[$key];
        }

        $meta = stream_get_meta_data($this->stream);

        return isset($meta[$key]) ? $meta[$key] : null;
    }
}
