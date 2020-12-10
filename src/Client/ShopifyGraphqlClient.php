<?php

declare(strict_types=1);

namespace Slepic\Shopify\Client;

use Slepic\Shopify\Credentials\ShopDomain;
use Slepic\Shopify\Http\JsonClientException;
use Slepic\Shopify\Http\JsonClientInterface;

final class ShopifyGraphqlClient implements ShopifyGraphqlClientInterface
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

    public function query(string $query, array $variables = []): ShopifyResponse
    {
        try {
            $response = $this->client->call(
                $this->shopDomain->getShopUrl(),
                'POST',
                '/admin/api/graphql.json',
                $this->headers,
                [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]
            );
        } catch (JsonClientException $e) {
            throw new ShopifyClientException($e->getMessage(), (int) $e->getCode(), $e);
        }

        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new ShopifyClientException(
                'Shopify GraphQL request failed with status ' . $response->getStatus(),
                $response->getStatus()
            );
        }

        $responseBody = $response->getBody();

        if ($responseBody['errors'] ?? false) {
            throw new ShopifyClientException(\sprintf(
                'Shopify GraphQL request failed with errors: %s',
                \json_encode($responseBody['errors'])
            ));
        }

        if (!isset($responseBody['data'])) {
            throw new ShopifyClientException('Shopify GraphQL client failed to recognize response data structure - missing data property');
        }

        $responseData = $responseBody['data'];

        if (
            isset($responseBody['extensions']['cost']['throttleStatus']['maximumAvailable']) &&
            isset($responseBody['extensions']['cost']['throttleStatus']['currentlyAvailable'])
        ) {
            $callsLimit = (int) $responseBody['extensions']['cost']['throttleStatus']['maximumAvailable'];
            $callsRemaining = (int) $responseBody['extensions']['cost']['throttleStatus']['currentlyAvailable'];
            $callsMade = $callsLimit - $callsRemaining;

            return ShopifyResponse::limited(
                $response->getStatus(),
                $responseData,
                $callsMade,
                $callsLimit
            );
        }

        return ShopifyResponse::unlimited($response->getStatus(), $responseData);
    }
}
