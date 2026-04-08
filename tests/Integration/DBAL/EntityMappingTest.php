<?php

namespace Tests\Integration\DBAL;

use Entities\Analytics\SearchAppearance;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\SearchAppearance as SearchAppearanceEnum;
use Tests\Integration\BaseIntegrationTestCase;

class EntityMappingTest extends BaseIntegrationTestCase
{
    public function testSearchAppearanceCanBePersistedAndRetrieved(): void
    {
        // 1. Create the entity
        $appearance = new SearchAppearance();
        $appearance->addType(SearchAppearanceEnum::AMP_STORY);
        
        // 2. Persist it using the integration EntityManager
        $this->entityManager->persist($appearance);
        
        // 3. Flush to the in-memory SQLite database
        $this->entityManager->flush();
        
        // 4. Clear the Doctrine UnitOfWork to ensure we are actually querying the DB
        // rather than just pulling from Doctrine's in-memory object cache
        $this->entityManager->clear();
        
        // 5. Query the database
        /** @var SearchAppearance $retrieved */
        $retrieved = $this->entityManager->getRepository(SearchAppearance::class)->findOneBy([
            'type' => SearchAppearanceEnum::AMP_STORY
        ]);
        
        // 6. Assertions
        $this->assertNotNull($retrieved);
        $this->assertSame(SearchAppearanceEnum::AMP_STORY, $retrieved->getType());
        $this->assertNotNull($retrieved->getId());
    }
}
