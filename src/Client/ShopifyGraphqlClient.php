<?php

declare(strict_types=1);

namespace LukeTowers\ShopifyPHP\Client;

final class ShopifyGraphqlClient implements ShopifyGraphqlClientInterface
{
    private ShopifyClientInterface $client;

    public function __construct(ShopifyClientInterface $client)
    {
        $this->client = $client;
    }

    public function query(string $query, array $variables = []): ShopifyResponse
    {
        $response = $this->client->call(
            'POST',
            '/admin/api/graphql.json',
            [
                'query' => $query,
                'variables' => (object) $variables,
            ]
        );

        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new ShopifyClientException(
                'Shopify GraphQL request failed with status ' . $response->getStatus(),
                $response->getStatus()
            );
        }

        return $response;
    }
}
