<?php

declare(strict_types=1);

namespace Waypost\Chain;

use Waypost\Enums\ErrorCode;

/** What became of the entries of a push (api/sync-push.response.json). */
final class PushResult
{
    /** The limit of both lists in the schema. A waiting entry over it is acknowledged when it is sent again. */
    private const int MAX_LISTED = 200;

    /** @var list<string> */
    private array $accepted = [];

    /** @var list<array{id: string, code: string, expected_seq: int|null}> */
    private array $rejected = [];

    public function accept(string $id): void
    {
        $this->accepted[] = $id;
    }

    public function reject(string $id, ErrorCode $code, ?int $expectedSeq = null): void
    {
        $this->rejected[] = ['id' => $id, 'code' => $code->value, 'expected_seq' => $expectedSeq];
    }

    /**
     * The body of the response.
     *
     * @param  list<string>  $mediaMissing
     * @return array<string, mixed>
     */
    public function response(int $lastSeq, array $mediaMissing): array
    {
        return [
            'accepted' => array_slice($this->accepted, 0, self::MAX_LISTED),
            'last_seq' => $lastSeq,
            'rejected' => array_slice($this->rejected, 0, self::MAX_LISTED),
            'media_missing' => $mediaMissing,
        ];
    }
}
