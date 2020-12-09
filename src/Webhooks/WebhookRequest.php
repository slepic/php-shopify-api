<?php

declare(strict_types=1);

namespace LukeTowers\ShopifyPHP\Webhooks;

use LukeTowers\ShopifyPHP\Credentials\ShopDomain;

final class WebhookRequest
{
    private ShopDomain $shopDomain;
    private string $topic;

    public function __construct(ShopDomain $shopDomain, string $topic)
    {
        $this->shopDomain = $shopDomain;
        $this->topic = $topic;
    }

    public function getShopDomain(): ShopDomain
    {
        return $this->shopDomain;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }
}
