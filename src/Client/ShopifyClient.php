<?php

declare(strict_types=1);

namespace Slepic\Shopify\Client;

use Slepic\Http\JsonApiClient\JsonClientExceptionInterface;
use Slepic\Http\JsonApiClient\JsonClientInterface;
use Slepic\Shopify\Credentials\ShopDomain;

final class ShopifyClient implements ShopifyClientInterface
{
    private JsonClientInterface $client;
    private ShopDomain $shopDomain;
    private array $headers;

    public function __construct(JsonClientInterface $client, ShopDomain $shopDomain, array $headers)
    {
        $this->client = $client;
        $this->shopDomain = $shopDomain;
        $this->headers = $headers;
    }

    public function call(string $method, string $endpoint, $body = null, array $query = []): ShopifyResponse
    {
        try {
            $response = $this->client->call(
                $this->shopDomain->getShopUrl(),
                $method,
                $endpoint,
                $query,
                $this->headers,
                $body
            );
        } catch (JsonClientExceptionInterface $e) {
            throw new ShopifyClientException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $matches = [];
        $apiCallLimitHeader = $response->getHeaderLine('X-Shopify-Shop-Api-Call-Limit');
        if (!$apiCallLimitHeader || !\preg_match('#^(\\d+)/(\\d+)$#', $apiCallLimitHeader, $matches)) {
            return ShopifyResponse::unlimited($response->getStatusCode(), $response->getParsedBody());
        }
        return ShopifyResponse::limited(
            $response->getStatusCode(),
            $response->getParsedBody(),
            (int) $matches[0],
            (int) $matches[1],
            1
        );
    }
}
