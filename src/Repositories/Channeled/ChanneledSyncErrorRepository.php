<?php

namespace Repositories\Channeled;

use Entities\Analytics\Channeled\ChanneledSyncError;

class ChanneledSyncErrorRepository extends ChanneledBaseRepository
{
    /**
     * logs a sync error to the database.
     * 
     * @param array $data
     * @return ChanneledSyncError|null
     */
    public function logError(array $data)
    {
        try {
            $error = new ChanneledSyncError();
            $error->addPlatformId($data['platformId'] ?? 'unknown')
                ->addChannel($this->validateChannel($data['channel']))
                ->addIdentifier($data['identifier'] ?? $data['platformId'] ?? 'unknown')
                ->addSyncType($data['syncType'] ?? 'unknown')
                ->addEntityType($data['entityType'] ?? 'unknown')
                ->addErrorMessage($data['errorMessage'] ?? null)
                ->addData($data['extraData'] ?? null);

            $this->_em->persist($error);
            $this->_em->flush();

            return $error;
        } catch (\Exception $e) {
            error_log("Failed to log sync error to database: " . $e->getMessage());
            return null;
        }
    }
}
