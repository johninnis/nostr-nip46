<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;

final readonly class Nip46Request
{
    /**
     * @param list<string> $params
     */
    private function __construct(
        private RequestId $id,
        private string $method,
        private array $params,
    ) {
    }

    /**
     * @param list<string> $params
     */
    public static function generate(string $method, array $params): self
    {
        return new self(RequestId::generate(), $method, $params);
    }

    public function getId(): RequestId
    {
        return $this->id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function param(int $index): ?string
    {
        return $this->params[$index] ?? null;
    }

    /**
     * @return array{id: string, method: string, params: list<string>}
     */
    public function toArray(): array
    {
        return ['id' => (string) $this->id, 'method' => $this->method, 'params' => $this->params];
    }

    public function toJson(): string
    {
        return JsonWireFormat::encode($this->toArray(), JsonWireFormat::MESSAGE);
    }

    public static function tryFromWire(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $id = RequestId::tryFromString($value['id'] ?? null);
        $method = $value['method'] ?? null;

        if (null === $id || !is_string($method) || '' === $method) {
            return null;
        }

        $rawParams = $value['params'] ?? [];

        if (!is_array($rawParams)) {
            return null;
        }

        $params = array_map(self::normaliseParam(...), array_values($rawParams));

        return new self($id, $method, $params);
    }

    private static function normaliseParam(mixed $param): string
    {
        if (is_string($param)) {
            return $param;
        }

        $encoded = json_encode($param, JsonWireFormat::MESSAGE);

        return false === $encoded ? '' : $encoded;
    }
}
