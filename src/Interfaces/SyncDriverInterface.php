<?php

namespace Interfaces;

use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface as BaseSyncDriverInterface;

/**
 * Interface SyncDriverInterface
 * Defines the contract for a Data Channel Driver (GSC, Ads, etc.)
 */
interface SyncDriverInterface extends BaseSyncDriverInterface
{
    /**
     * @param array $config
     * @return mixed
     */
    public function getApi(array $config = []): mixed;
}
