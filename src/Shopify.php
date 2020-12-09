<?php

declare(strict_types=1);

namespace LukeTowers\ShopifyPHP;

use LukeTowers\ShopifyPHP\Client\ShopifyClient;
use LukeTowers\ShopifyPHP\Client\ShopifyClientInterface;
use LukeTowers\ShopifyPHP\Credentials\AccessToken;
use LukeTowers\ShopifyPHP\Credentials\ApiCredentials;
use LukeTowers\ShopifyPHP\Credentials\ApiKey;
use LukeTowers\ShopifyPHP\Credentials\ShopDomain;
use LukeTowers\ShopifyPHP\Credentials\ShopDomainException;
use LukeTowers\ShopifyPHP\Http\JsonClient;
use LukeTowers\ShopifyPHP\Http\JsonClientException;
use LukeTowers\ShopifyPHP\Http\JsonClientInterface;
use LukeTowers\ShopifyPHP\OAuth\AuthorizationException;
use LukeTowers\ShopifyPHP\OAuth\AuthorizationRequest;
use LukeTowers\ShopifyPHP\OAuth\AuthorizationResponse;
use LukeTowers\ShopifyPHP\OAuth\Scopes;
use LukeTowers\ShopifyPHP\Webhooks\WebhookRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

class Shopify
{
    private JsonClientInterface $client;
    private ApiCredentials $credentials;

    public function __construct(JsonClientInterface $client, ApiCredentials $credentials)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public static function create(RequestFactoryInterface $requestFactory, ClientInterface $client, ApiCredentials $credentials): self
    {
        return new self(new JsonClient($requestFactory, $client), $credentials);
    }

    public static function privateAppClient(
        RequestFactoryInterface $requestFactory,
        ClientInterface $client,
        ShopDomain $shopDomain,
        ApiCredentials $credentials
    ): ShopifyClientInterface {
        return ShopifyClient::forPrivateApp(new JsonClient($requestFactory, $client), $shopDomain, $credentials);
    }

    public static function publicAppClient(
        RequestFactoryInterface $requestFactory,
        ClientInterface $client,
        ShopDomain $shopDomain,
        AccessToken $accessToken
    ): ShopifyClientInterface {
        return ShopifyClient::forPublicApp(new JsonClient($requestFactory, $client), $shopDomain, $accessToken);
    }

    public static function publicApp(
        RequestFactoryInterface $requestFactory,
        ClientInterface $client,
        ApiCredentials $credentials,
        string $redirectUrl,
        Scopes $requiredScopes,
        ?Scopes $optionalScopes = null
    ): ShopifyPublicApp
    {
        return self::create($requestFactory, $client, $credentials)
            ->createPublicApp($redirectUrl, $requiredScopes, $optionalScopes);
    }

    public function getApiKey(): ApiKey
    {
        return $this->credentials->getApiKey();
    }

    public function validateShopRequest(array $requestData): ShopDomain
    {
        return ShopDomain::create($requestData['shop'] ?? null);
    }

    public function getAuthorizationUrl(
        ShopDomain $shopDomain,
        Scopes $scopes,
        string $redirectUrl,
        string $nonce = '',
        bool $onlineAccessMode = false
    ): string {
        $args = [
            'client_id'    => (string) $this->credentials->getApiKey(),
            'scope'        => (string) $scopes,
            'redirect_uri' => $redirectUrl,
            'state'        => $nonce,
        ];

        if ($onlineAccessMode) {
            $args['grant_options[]'] = 'per-user';
        }

        return $shopDomain->getShopUrl() . '/admin/oauth/authorize?' . http_build_query($args);
    }

    /**
     * @param array $requestData
     * @return ShopDomain
     * @throws AuthorizationException
     */
    public function validateSecuredRequest(array $requestData): ShopDomain
    {
        try {
            $requestShopDomain = $this->validateShopRequest($requestData);
        } catch (\Throwable $e) {
            throw new AuthorizationException("The shop provided by Shopify is invalid: " . $e->getMessage());
        }

        $requiredKeys = ['hmac'];
        foreach ($requiredKeys as $required) {
            if (!in_array($required, array_keys($requestData))) {
                throw new AuthorizationException(
                    "The provided request data is missing one of the following keys: " . implode(', ', $requiredKeys)
                );
            }
        }

        // Check HMAC signature. See https://help.shopify.com/api/getting-started/authentication/oauth#verification
        $hmacSource = [];
        foreach ($requestData as $key => $value) {
            if ($key === 'hmac') {
                continue;
            }

            // Replace the characters as specified by Shopify in the keys and values
            $valuePatterns = [
                '&' => '%26',
                '%' => '%25',
            ];
            $keyPatterns = array_merge($valuePatterns, ['=' => '%3D']);
            $key = str_replace(array_keys($keyPatterns), array_values($keyPatterns), $key);
            $value = str_replace(array_keys($valuePatterns), array_values($valuePatterns), $value);

            $hmacSource[] = $key . '=' . $value;
        }

        // Sort the key value pairs lexographically and then generate the HMAC signature of the provided data
        sort($hmacSource);
        $hmacBase = implode('&', $hmacSource);
        $hmacString = hash_hmac('sha256', $hmacBase, (string) $this->credentials->getSecret());

        // Verify that the signatures match
        if ($hmacString !== $requestData['hmac']) {
            throw new AuthorizationException(\sprintf(
                "The HMAC provided by Shopify (%s) doesn't match the HMAC verification (%s).",
                $requestData['hmac'],
                $hmacString
            ));
        }

        return $requestShopDomain;
    }

    /**
     * @param RequestInterface $request
     * @return WebhookRequest
     * @throws AuthorizationException
     */
    public function validateWebhookRequest(RequestInterface  $request): WebhookRequest
    {
        $shopDomainHeader = $request->getHeaderLine('X-Shopify-Shop-Domain');
        $topicHeader = $request->getHeaderLine('X-Shopify-Topic');
        $hmacHeader = $request->getHeaderLine('X-Shopify-Hmac-Sha256');
        $contentTypeHeader = $request->getHeaderLine('Content-Type');

        try {
            $shopDomain = ShopDomain::create($shopDomainHeader);
        } catch (\Throwable $e) {
            throw new AuthorizationException("The shop provided by Shopify is invalid: " . $e->getMessage());
        }

        if (!$topicHeader) {
            throw new AuthorizationException('Missing webhook topic');
        }

        if (!$hmacHeader) {
            throw new AuthorizationException('Missing webhook signature');
        }

        if (!$contentTypeHeader) {
            throw new AuthorizationException('Missing webhook content type');
        }

        $requestBody = (string) $request->getBody();

        $hmacString = base64_encode(hash_hmac('sha256', $requestBody, (string) $this->credentials->getSecret(), true));

        // Verify that the signatures match
        if (!\hash_equals($hmacHeader, $hmacString)) {
            throw new AuthorizationException(\sprintf(
                "The HMAC provided by Shopify (%s) doesn't match the HMAC verification (%s).",
                $hmacHeader,
                $hmacString
            ));
        }

        if (\strpos($contentTypeHeader, 'application/json') !== false) {
            $data = \json_decode($requestBody, true);
            if (!\is_array($data)) {
                throw new AuthorizationException('Failed to decode webhook body: ' . $requestBody);
            }
        } else {
            throw new AuthorizationException('Unsupported webhook content type ' . $contentTypeHeader);
        }

        return new WebhookRequest($shopDomain, $topicHeader, $data);
    }

    /**
     * @param array $requestData
     * @param string $nonce
     * @param ShopDomain|null $shopDomain
     * @return AuthorizationRequest
     * @throws AuthorizationException
     */
    public function validateAuthorizationRequest(array $requestData, string $nonce = '', ?ShopDomain $shopDomain = null): AuthorizationRequest
    {
        if (!isset($requestData['code']) || !\is_string($requestData['code']) || empty($requestData['code'])) {
            throw new AuthorizationException("Invalid or missing grant code.");
        }

        if (($requestData['state'] ?? null)  !== $nonce) {
            throw new AuthorizationException("Invalid or missing nonce.");
        }

        $requestShopDomain = $this->validateSecuredRequest($requestData);

        if ($shopDomain !== null && !$shopDomain->equals($requestShopDomain)) {
            throw new AuthorizationException(\sprintf(
                "The shop provided by Shopify (%s) does not match the shop provided to this API (%s)",
                (string) $requestShopDomain,
                (string) $shopDomain
            ));
        }

        return new AuthorizationRequest($requestShopDomain, $requestData['code']);
    }

    /**
     * @param AuthorizationRequest $request
     * @return AuthorizationResponse
     * @throws AuthorizationException
     */
    public function authorizeApplication(AuthorizationRequest $request): AuthorizationResponse
    {
        try {
            $response = $this->client->call(
                $request->getShopDomain()->getShopUrl(),
                'POST',
                '/admin/oauth/access_token',
                [],
                [
                    'client_id'     => (string) $this->credentials->getApiKey(),
                    'client_secret' => (string) $this->credentials->getSecret(),
                    'code'          => $request->getCode(),
                ]
            );
        } catch (JsonClientException $e) {
            throw new AuthorizationException('Authorization request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatus();
        if ($status !== 200) {
            throw new AuthorizationException('Unexpected authorization response status ' . $status, $status);
        }
        return AuthorizationResponse::fromArray($response->getBody());
    }

    public function createPublicAppClient(ShopDomain $shopDomain, AccessToken $accessToken): ShopifyClientInterface
    {
        return ShopifyClient::forPublicApp($this->client, $shopDomain, $accessToken);
    }

    public function createPrivateAppClient(ShopDomain $shopDomain): ShopifyClientInterface
    {
        return ShopifyClient::forPrivateApp($this->client, $shopDomain, $this->credentials);
    }

    public function createPublicApp(string $redirectUrl, Scopes $requiredScopes, ?Scopes $optionalScopes = null): ShopifyPublicApp
    {
        return new ShopifyPublicApp($this, $redirectUrl, $requiredScopes, $optionalScopes);
    }
}
