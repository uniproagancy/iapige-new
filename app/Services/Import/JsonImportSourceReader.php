<?php

namespace App\Services\Import;

/** A small verified window over a frozen upload, rather than a whole-file string. */
class JsonImportSourceReader
{
    protected int $position = 0;

    protected int $block = -1;

    protected string $buffer = '';

    public function __construct(protected $source, protected $receipts, protected int $size) {}

    public function seek(int $position): void
    {
        if ($position < 0 || $position > $this->size) {
            throw new JsonImportSourceException('json_cursor_invalid');
        }

        $this->position = $position;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function peek(): ?string
    {
        if ($this->position >= $this->size) {
            return null;
        }

        $this->load();

        return $this->buffer[$this->position % JsonImportSource::CHUNK_BYTES];
    }

    public function byte(): ?string
    {
        $byte = $this->peek();

        if ($byte !== null) {
            $this->position++;
        }

        return $byte;
    }

    public function span(string $special): string
    {
        if ($this->peek() === null) {
            return '';
        }

        $offset = $this->position % JsonImportSource::CHUNK_BYTES;
        $length = strcspn($this->buffer, $special, $offset);
        $this->position += $length;

        return substr($this->buffer, $offset, $length);
    }

    protected function load(): void
    {
        $block = intdiv($this->position, JsonImportSource::CHUNK_BYTES);

        if ($block === $this->block) {
            return;
        }

        $offset = $block * JsonImportSource::CHUNK_BYTES;
        $length = min(JsonImportSource::CHUNK_BYTES, $this->size - $offset);

        if (fseek($this->source, $offset) !== 0 || fseek($this->receipts, $block * 32) !== 0) {
            throw new JsonImportSourceException('json_source_changed', 409);
        }

        $buffer = '';

        while (strlen($buffer) < $length && ! feof($this->source)) {
            $piece = fread($this->source, $length - strlen($buffer));

            if ($piece === false || $piece === '') {
                break;
            }

            $buffer .= $piece;
        }

        $expected = fread($this->receipts, 32);

        if (strlen($buffer) !== $length || ! is_string($expected) || strlen($expected) !== 32
            || ! hash_equals($expected, hash('sha256', $buffer, true))) {
            throw new JsonImportSourceException('json_source_changed', 409);
        }

        $this->buffer = $buffer;
        $this->block = $block;
    }
}
