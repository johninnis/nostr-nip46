<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

final readonly class PairingUri
{
    private const string QUERY_SEPARATOR = '?';
    private const string PARAMETER_SEPARATOR = '&';
    private const string VALUE_SEPARATOR = '=';

    /**
     * @param array<string, list<string>> $parameters
     */
    private function __construct(
        private string $origin,
        private array $parameters,
    ) {
    }

    public static function tryFromString(string $raw, string $scheme): ?self
    {
        $trimmed = trim($raw);

        if (!str_starts_with($trimmed, $scheme)) {
            return null;
        }

        $rest = substr($trimmed, strlen($scheme));
        $queryStart = strpos($rest, self::QUERY_SEPARATOR);

        if (false === $queryStart) {
            return new self($rest, []);
        }

        return new self(substr($rest, 0, $queryStart), self::parseQuery(substr($rest, $queryStart + 1)));
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    /**
     * @return list<string>
     */
    public function values(string $key): array
    {
        return $this->parameters[$key] ?? [];
    }

    public function first(string $key): ?string
    {
        return $this->values($key)[0] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseQuery(string $query): array
    {
        $parameters = [];

        foreach (explode(self::PARAMETER_SEPARATOR, $query) as $pair) {
            if ('' === $pair) {
                continue;
            }

            [$key, $value] = array_pad(explode(self::VALUE_SEPARATOR, $pair, 2), 2, '');
            $parameters[urldecode($key)][] = urldecode($value);
        }

        return $parameters;
    }
}
