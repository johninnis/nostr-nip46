<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use InvalidArgumentException;

/**
 * @template TValue
 */
final class BoundedMap
{
    /** @var array<string, TValue> */
    private array $entries = [];

    public function __construct(private readonly int $limit)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Bounded-map limit must be at least 1, got '.$limit);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * @return TValue|null
     */
    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    /**
     * @param TValue $value
     */
    public function set(string $key, mixed $value): void
    {
        $this->entries[$key] = $value;

        if (count($this->entries) > $this->limit) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }
}
