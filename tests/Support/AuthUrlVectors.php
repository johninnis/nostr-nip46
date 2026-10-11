<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use RuntimeException;

final class AuthUrlVectors
{
    private const string PATH = __DIR__.'/../Vectors/auth-url.json';

    /** @var list<array{?string, ?string}>|null */
    private static ?array $vectors = null;

    /**
     * @return list<array{?string, ?string}>
     */
    public static function all(): array
    {
        return self::$vectors ??= self::load();
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function cases(): iterable
    {
        foreach (self::all() as [$error, $handedOver]) {
            yield json_encode($error, JSON_THROW_ON_ERROR) => [$error, $handedOver];
        }
    }

    /**
     * @return list<array{?string, ?string}>
     */
    private static function load(): array
    {
        $json = file_get_contents(self::PATH);

        if (false === $json) {
            throw new RuntimeException('Cannot read tests/Vectors/auth-url.json');
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !is_array($decoded['vectors'] ?? null)) {
            throw new RuntimeException('auth-url.json has no vectors list');
        }

        return array_values(array_map(self::vector(...), $decoded['vectors']));
    }

    /**
     * @return array{?string, ?string}
     */
    private static function vector(mixed $row): array
    {
        $error = is_array($row) ? ($row[0] ?? null) : null;
        $handedOver = is_array($row) ? ($row[1] ?? null) : null;

        if (!is_array($row) || (null !== $error && !is_string($error)) || (null !== $handedOver && !is_string($handedOver))) {
            throw new RuntimeException('auth-url.json holds a vector that is not a challenge error and a string or null expectation');
        }

        return [$error, $handedOver];
    }
}
