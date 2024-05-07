<?php
namespace phasync\Psr;

use phasync\Legacy\Loop;
use phasync\UsageError;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * This StreamInterface implementation is backed by a PHP
 * stream resource, such a file handle or a socket connection.
 * 
 * @package phasync
 */
final class ResourceStream implements StreamInterface {

    /**
     * True if the stream resource has been closed.
     * 
     * @var bool
     */
    protected bool $closed = true;

    /**
     * True if the stream resource has been detached.
     * 
     * @var bool
     */
    protected bool $detached = true;

    /**
     * The stream resource or null if detached or closed.
     * 
     * @var resource|null
     */
    protected mixed $resource = null;

    /**
     * Used to override the apparent mode of this stream resource.
     * 
     * @var null|string
     */
    private ?string $mode = null;

    /**
     * Construct a PSR compliant stream resource which integrates
     * with phasync for asynchronous IO. If created using a
     * StreamInterface, the original StreamInterface object will
     * be detached.
     * 
     * @param resource|StreamInterface $resource 
     * @param ?string $mode If set will override the actual mode
     * @return void 
     * @throws UsageError 
     */
    public function __construct(mixed $resource, string $mode=null) {
        $this->setResource($resource);
        if ($mode !== null) {
            $this->mode = $mode;
        }
    }

    /**
     * Reinitialize the stream resource.
     * 
     * @param mixed $resource 
     * @return void 
     * @throws UsageError 
     */
    protected function setResource(mixed $resource): void {
        if ($resource instanceof StreamInterface) {
            $resource = $resource->detach();
        }
        if (!\is_resource($resource) || \get_resource_type($resource) !== "stream") {
            throw new UsageError("Not a stream resource");
        }
        $this->closed = false;
        $this->detached = false;
        $this->resource = $resource;
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     * @return string
     */
    public function __toString(): string {
        if ($this->closed || $this->detached || $this->getSize() === 0) {
            return '';
        }
        $this->seek(0);
        $chunks = [];
        try {
            while (!\feof($this->resource)) {
                $chunk = $this->read(32768);
                if ($chunk === '') {
                    Loop::yield();
                } else {
                    $chunks[] = $chunk;
                }
            }
            return \implode("", $chunks);
        } catch (RuntimeException) {
            return '';
        }

    }

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close(): void {
        if ($this->closed || $this->detached) {
            return;
        }
        $this->closed = true;
        \fclose($this->resource);
        $this->resource = null;
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach() {
        if ($this->closed || $this->detached) {
            return null;
        }
        $this->detached = true;
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize(): ?int {
        if ($this->closed || $this->detached) {
            return null;
        }        
        $stat = \fstat($this->resource);
        return (int) $stat['size'];
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int Position of the file pointer
     * @throws \RuntimeException on error.
     */
    public function tell(): int {
        if ($this->closed || $this->detached) {
            throw new RuntimeException("Stream is closed or detached");
        }
        return \ftell($this->resource);
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */    
    public function eof(): bool { 
        if ($this->closed || $this->detached) {
            return true;
        }
        return \feof($this->resource);
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable(): bool {
        if ($this->closed || $this->detached) {
            return false;
        }
        return \stream_get_meta_data($this->resource)['seekable'] ?? false;
    }

    /**
     * Seek to a position in the stream.
     *
     * @link http://www.php.net/manual/en/function.fseek.php
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *     based on the seek offset. Valid values are identical to the built-in
     *     PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *     offset bytes SEEK_CUR: Set position to current location plus offset
     *     SEEK_END: Set position to end-of-stream plus offset.
     * @throws \RuntimeException on failure.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void {
        if ($this->closed || $this->detached) {
            throw new RuntimeException("Stream is closed or detached");            
        }
        $result = \fseek($this->resource, $offset, $whence);
        if ($result !== 0) {
            throw new RuntimeException("Unable to seek");
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @link http://www.php.net/manual/en/function.fseek.php
     * @throws \RuntimeException on failure.
     */
    public function rewind(): void {
        if ($this->closed || $this->detached) {
            throw new RuntimeException("Stream is closed or detached");
        }
        if (!$this->isSeekable()) {
            throw new RuntimeException("Stream is not seekable");
        }
        \rewind($this->resource);
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable(): bool {
        if ($this->closed || $this->detached) {
            return false;
        }
        $mode = $this->mode ?? \stream_get_meta_data($this->resource)['mode'] ?? null;
        return $mode !== null && (\str_contains($mode, '+') || \str_contains($mode, 'x') || \str_contains($mode, 'w') || \str_contains($mode, 'a') || \str_contains($mode, 'c'));
    }

    /**
     * Write data to the stream.
     *
     * @param string $string The string that is to be written.
     * @return int Returns the number of bytes written to the stream.
     * @throws \RuntimeException on failure.
     */
    public function write(string $string): int {
        if ($this->closed || $this->detached) {
            throw new RuntimeException("Stream is closed or detached");
        }
        if (!$this->isWritable()) {
            throw new RuntimeException("Stream is not writable");
        }
        Loop::writable($this->resource);
        $result = \fwrite($this->resource, $string);
        if ($result === false) {
            throw new RuntimeException("Failed writing to stream");
        }
        return $result;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable(): bool {
        if ($this->closed || $this->detached) {
            return false;
        }
        $mode = $this->mode ?? \stream_get_meta_data($this->resource)['mode'] ?? null;
        return $mode !== null && (\str_contains($mode, '+') || \str_contains($mode, 'r'));
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *     them. Fewer than $length bytes may be returned if underlying stream
     *     call returns fewer bytes.
     * @return string Returns the data read from the stream, or an empty string
     *     if no bytes are available.
     * @throws \RuntimeException if an error occurs.
     */
    public function read(int $length): string {
        if ($this->closed || $this->detached) {
            throw new RuntimeException("Stream is closed or detached");
        }
        if ($length < 0) {
            throw new RuntimeException("Can't read a negative amount");
        }
        if (!$this->isReadable()) {
            throw new RuntimeException("Stream is not writable");
        }
        Loop::readable($this->resource);
        $result = \fread($this->resource, $length);
        if ($result === false) {
            throw new RuntimeException("Failed writing to stream");
        }
        return $result;
    }

    /**
     * Returns the remaining contents in a string
     *
     * @return string
     * @throws \RuntimeException if unable to read or an error occurs while
     *     reading.
     */    
    public function getContents(): string {
        if ($this->closed || $this->detached) {
            throw new RuntimeException("Stream is closed or detached");
        }
        $buffer = [];
        while (!$this->eof()) {
            $chunk = $this->read(32768);
            if ($chunk !== '') {
                $buffer[] = $chunk;
            }
        }
        return \implode("", $buffer);
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @link http://php.net/manual/en/function.stream-get-meta-data.php
     * @param string|null $key Specific metadata to retrieve.
     * @return array|mixed|null Returns an associative array if no key is
     *     provided. Returns a specific key value if a key is provided and the
     *     value is found, or null if the key is not found.
     */
    public function getMetadata(?string $key = null) {
        if ($this->closed || $this->detached) {
            return null;
        }
        $meta = \stream_get_meta_data($this->resource);
        if ($key !== null) {
            return $meta[$key] ?? null;
        }
        return $meta;
    }

}