<?php

declare(strict_types=1);

namespace Slepic\Shopify\Client;

final class ShopifyResponse
{
    private int $status;
    private array $body;
    private ?int $callsMade;
    private ?int $callLimit;
    private ?int $cost;

    private function __construct(int $status, array $body, ?int $callsMade = null, ?int $callLimit = null, ?int $cost = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->callsMade = $callsMade;
        $this->callLimit = $callLimit;
        $this->cost = $cost;
    }

    public static function unlimited(int $status, array $body): self
    {
        return new self($status, $body);
    }

    public static function limited(int $status, array $body, int $callsMade, int $callLimit, int $cost): self
    {
        return new self($status, $body, $callsMade, $callLimit, $cost);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function getCallsMade(): ?int
    {
        return $this->callsMade;
    }

    public function getCallLimit(): ?int
    {
        return $this->callLimit;
    }

    public function getCost(): ?int
    {
        return $this->cost;
    }
}
