<?php

namespace Interfaces;

use Symfony\Component\HttpFoundation\Response;
use DateTime;

/**
 * Interface SyncDriverInterface
 * Defines the contract for a Data Channel Driver (GSC, Ads, etc.)
 */
interface SyncDriverInterface
{
    /**
     * Authenticate the driver with a specific provider.
     */
    public function setAuthProvider(AuthProviderInterface $provider): void;

    /**
     * Perform the synchronization loop for a date range.
     */
    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response;

    /**
     * Get the channel identifier (e.g. google_search_console).
     */
    public function getChannel(): string;
}
