<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Smpp\Client;
use Smpp\Smpp;

/**
 * Regression test for the GSM slow-split path in splitMessageString().
 *
 * The slow path is entered when a GSM 03.38 escape character (\x1B) lands on
 * a chunk boundary (i.e., it would be the last byte of a chunk). The method
 * must not emit a chunk ending with \x1B — instead it breaks the chunk one
 * character early so the escape + the next char stay together in the next part.
 */
class SplitMessageSlowPathTest extends TestCase
{
    private ReflectionMethod $method;

    protected function setUp(): void
    {
        $this->method = new ReflectionMethod(Client::class, 'splitMessageString');
        $this->method->setAccessible(true);
    }

    /**
     * Call splitMessageString on a minimal Client stub.
     *
     * @return array<int, string>
     */
    private function split(string $message, int $chunkSize): array
    {
        // We only need a Client instance to invoke the protected method; the
        // transport is never contacted, so we use a simple null stub.
        $transport = new class implements \Smpp\Contracts\Transport\TransportInterface {
            public function open(): void {}
            public function isOpen(): bool { return false; }
            public function close(): void {}
            public function read(int $length): string { return ''; }
            public function write(string $data, int $length): void {}
            public function hasData(): bool { return false; }
        };

        $client = new Client($transport, '', '');

        /** @var array<int, string> */
        return $this->method->invoke($client, $message, $chunkSize, Smpp::DATA_CODING_DEFAULT);
    }

    /**
     * A \x1B at the last position of the first chunk (index chunkSize-1) must
     * trigger the slow path and push \x1B into the SECOND part together with
     * the character that follows it.
     *
     * With chunkSize=5 and message "ABCD\x1BEF" (7 chars):
     *   - chunk boundary check: $message[4] == "\x1B"  → slowSplit = true
     *   - expected parts: ["ABCD", "\x1BEF"]
     */
    public function testSlowSplitPushesEscapeCharIntoNextChunk(): void
    {
        $chunkSize = 5;
        $message   = "ABCD\x1BEF"; // 7 chars; \x1B at index 4 == chunkSize-1

        $parts = $this->split($message, $chunkSize);

        $this->assertCount(2, $parts, 'Message must be split into exactly 2 parts');
        $this->assertSame('ABCD', $parts[0], 'First part must be the 4 chars before the escape');
        $this->assertSame("\x1BEF", $parts[1], 'Second part must start with the escape char');
    }

    /**
     * Verify the split correctly handles a longer message where the slow path
     * must produce three parts.
     *
     * With chunkSize=5 and "ABCD\x1BEFGH\x1BIJ" (14 chars):
     *   - \x1B at index 4 triggers slow split
     *   - expected parts: ["ABCD", "\x1BEFGH", "\x1BIJ"]
     */
    public function testSlowSplitMultipleChunksWithEscapeAtBoundary(): void
    {
        $chunkSize = 5;
        // \x1B at index 4 (first boundary) and index 10 (second boundary, i.e.
        // the 5th char of the second part "\x1BEFGH" which has length 5).
        $message = "ABCD\x1BEFGH\x1BIJ";

        $parts = $this->split($message, $chunkSize);

        $this->assertCount(3, $parts, 'Message must be split into exactly 3 parts');
        $this->assertSame('ABCD',    $parts[0]);
        $this->assertSame("\x1BEFGH", $parts[1]);
        $this->assertSame("\x1BIJ",  $parts[2]);
    }
}
