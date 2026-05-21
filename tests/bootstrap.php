<?php

    declare(strict_types=1);

    require_once __DIR__.'/../vendor/autoload.php';

    use Helpers\Helpers;
    use Symfony\Component\Yaml\Yaml;

    // ─── Config resolution ────────────────────────────────────────────────────────
    $GLOBALS['app_config'] = Helpers::getProjectConfig();

    // Ensure CONFIG_DIR points to the project config for DriverFactory registry loading
    if (!getenv('CONFIG_DIR') || getenv('CONFIG_DIR') === 'config') {
        putenv('CONFIG_DIR='.__DIR__.'/../config');
    }

    if (empty($GLOBALS['app_config'])) {
        echo "ℹ️  No configuration source found.\n";
        echo "   Test infrastructure (DB, Redis) will be resolved from TEST_DB_* / REDIS_* env vars.\n";
        echo "   Channel credentials (Google, Facebook, …) come from CHANNELS_CONFIG env var.\n\n";
    }

    // ─── Helper ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a value from the bootstrap config array.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function app_config(string $key = null, mixed $default = null): mixed
    {
        $config = $GLOBALS['app_config'] ?? [];
        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }

    // ─── Channel Mock Compatibility for Isolated Tests ───────────────────────

    class MockChannelRepository extends \Doctrine\ORM\EntityRepository
    {
        private array $channels = [];

        public function __construct()
        {
            $channelData = [
                1  => ['name' => 'shopify', 'label' => 'Shopify'],
                2  => ['name' => 'google', 'label' => 'Google'],
                3  => ['name' => 'meta', 'label' => 'Meta'],
                4  => ['name' => 'klaviyo', 'label' => 'Klaviyo'],
                5  => ['name' => 'netsuite', 'label' => 'NetSuite'],
                6  => ['name' => 'amazon', 'label' => 'Amazon'],
                7  => ['name' => 'bigcommerce', 'label' => 'BigCommerce'],
                8  => ['name' => 'pinterest', 'label' => 'Pinterest'],
                9  => ['name' => 'linkedin', 'label' => 'LinkedIn'],
                10 => ['name' => 'tiktok', 'label' => 'TikTok'],
                11 => ['name' => 'x', 'label' => 'X'],
                12 => ['name' => 'triple-whale', 'label' => 'Triple Whale'],
            ];

            foreach ($channelData as $id => $data) {
                $channel = new \Entities\Analytics\Channel();
                $channel->setName($data['name']);
                $channel->setLabel($data['label']);

                try {
                    $ref = new \ReflectionClass($channel);
                    while ($ref && !$ref->hasProperty('id')) {
                        $ref = $ref->getParentClass();
                    }
                    if ($ref) {
                        $prop = $ref->getProperty('id');
                        $prop->setAccessible(true);
                        $prop->setValue($channel, $id);
                    }
                } catch (\Throwable $e) {
                }
                $this->channels[$id] = $channel;
            }
        }

        public function find($id, $lockMode = null, $lockVersion = null): ?object
        {
            return $this->channels[$id] ?? null;
        }

        public function findOneBy(array $criteria, ?array $orderBy = null): ?object
        {
            if (isset($criteria['name'])) {
                foreach ($this->channels as $channel) {
                    if ($channel->getName() === $criteria['name']) {
                        return $channel;
                    }
                }
            }

            return null;
        }
    }

    class DummyEntityRepository extends \Doctrine\ORM\EntityRepository
    {
        public function __construct()
        {
        }

        public function find($id, $lockMode = null, $lockVersion = null): ?object
        {
            return null;
        }

        public function findOneBy(array $criteria, ?array $orderBy = null): ?object
        {
            return null;
        }
    }

    class MockEntityManager extends \Doctrine\ORM\EntityManager
    {
        private MockChannelRepository $channelRepository;
        private DummyEntityRepository $dummyRepository;

        public function __construct()
        {
            $this->channelRepository = new MockChannelRepository();
            $this->dummyRepository = new DummyEntityRepository();
        }

        public function getRepository(string $className): \Doctrine\ORM\EntityRepository
        {
            if (str_contains($className, 'Channel') && !str_contains($className, 'Channeled')) {
                return $this->channelRepository;
            }

            return $this->dummyRepository;
        }

        public function isOpen(): bool
        {
            return true;
        }
    }

    Helpers::setEntityManager(new MockEntityManager());