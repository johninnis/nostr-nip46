<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;

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

        // Deliberate: an absent params member reads as an empty list, an explicit null is refused — see nostr-adrs ADR-0116
        $rawParams = array_key_exists('params', $value) ? $value['params'] : [];

        if (!is_array($rawParams) || !array_is_list($rawParams)) {
            return null;
        }

        $params = self::paramsFrom($method, $rawParams);

        return null === $params ? null : new self($id, $method, $params);
    }

    /**
     * @param list<mixed> $rawParams
     *
     * @return list<string>|null
     */
    private static function paramsFrom(string $method, array $rawParams): ?array
    {
        $params = array_values(array_filter(
            array_map(
                static fn (int $index, mixed $param): ?string => self::normaliseParam($method, $index, $param),
                array_keys($rawParams),
                $rawParams,
            ),
            static fn (?string $param): bool => null !== $param,
        ));

        return count($params) === count($rawParams) ? $params : null;
    }

    // Deliberate: only sign_event's event may arrive as a JSON object, encoded to the string it should have been; any other non-string parameter is refused — see nostr-adrs ADR-0116
    private static function normaliseParam(string $method, int $index, mixed $param): ?string
    {
        if (is_string($param)) {
            return $param;
        }

        $isSignEventEvent = Nip46Method::SignEvent->value === $method && 0 === $index;

        return $isSignEventEvent && null !== JsonWireFormat::objectFields($param)
            ? JsonWireFormat::encode($param, JsonWireFormat::MESSAGE)
            : null;
    }
}
