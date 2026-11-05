<?php

declare(strict_types=1);

namespace Services\Catalog;

use InvalidArgumentException;

final class CatalogEntityReference
{
    public const array SUPPORTED_LAYERS = ['channeled', 'base'];

    public const array ALL_ENTITY_TYPES = [
        'accounts',
        'campaigns',
        'ad-sets',
        'ad-groups',
        'ads',
        'customers',
        'pages',
        'posts',
        'queries',
        'events',
        'orders',
        'products',
    ];

    public const array PHASE_1_ADS_ENTITY_TYPES = [
        'accounts',
        'campaigns',
        'ad-sets',
        'ad-groups',
        'ads',
    ];

    public const array SUPPORTED_ENTITY_TYPES = [
        'accounts',
        'campaigns',
        'ad-sets',
        'ad-groups',
        'ads',
        'pages',
        'posts',
        'queries',
        'events',
        'customers',
    ];

    public const array PHASE_1_ADS_CHANNELS = [
        'facebook_marketing',
        'google_ads',
    ];

    public const array SUPPORTED_CHANNELS = [
        'facebook_marketing',
        'google_ads',
        'facebook_organic',
        'google_search_console',
        'google_analytics',
        'mailchimp',
        'facebook_leads',
    ];

    public function __construct(
        private readonly string $layer,
        private readonly string $channel,
        private readonly string $entityType,
        private readonly int $id,
    ) {
        if (!in_array($this->layer, self::SUPPORTED_LAYERS, true)) {
            throw new InvalidArgumentException("Unsupported catalog reference layer '{$this->layer}'.");
        }

        if ($this->channel === '' || !preg_match('/^[a-z0-9_-]+$/', $this->channel)) {
            throw new InvalidArgumentException("Invalid catalog reference channel '{$this->channel}'.");
        }

        if (!in_array($this->entityType, self::ALL_ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported catalog entity type '{$this->entityType}'.");
        }

        if ($this->id <= 0) {
            throw new InvalidArgumentException('Catalog reference ID must be a positive integer.');
        }
    }

    public static function format(string $layer, string $channel, string $entityType, int|string $id): string
    {
        $normalizedId = (int)$id;
        $ref = new self(
            strtolower(trim($layer)),
            strtolower(trim($channel)),
            strtolower(trim($entityType)),
            $normalizedId
        );

        return $ref->toString();
    }

    public static function parse(string $reference): self
    {
        $trimmed = trim(urldecode($reference));
        $parts = explode(':', $trimmed);
        if (count($parts) !== 4) {
            throw new InvalidArgumentException("Malformed catalog entity reference '{$reference}'.");
        }

        [$layer, $channel, $entityType, $rawId] = $parts;
        if (!ctype_digit($rawId) || (int)$rawId <= 0) {
            throw new InvalidArgumentException("Catalog entity reference ID must be a positive integer in '{$reference}'.");
        }

        return new self(
            strtolower(trim($layer)),
            strtolower(trim($channel)),
            strtolower(trim($entityType)),
            (int)$rawId
        );
    }

    public static function tryParse(?string $reference): ?self
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        try {
            return self::parse($reference);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function getLayer(): string
    {
        return $this->layer;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function toString(): string
    {
        return sprintf('%s:%s:%s:%d', $this->layer, $this->channel, $this->entityType, $this->id);
    }
}
