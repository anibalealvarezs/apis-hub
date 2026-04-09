<?php

namespace Core\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Psr\Log\LoggerInterface;
use Exception;

class DriverFactory
{
    private static array $instances = [];
    
    /**
     * Mapeo de canales a sus respectivas clases de Driver y AuthProvider.
     */
    private static array $registry = [];

    /**
     * Carga el registro de drivers desde el archivo de configuración.
     */
    private static function loadRegistry(): void
    {
        if (!empty(self::$registry)) {
            return;
        }

        $configDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../../config';
        $filePath = $configDir . '/drivers.yaml';

        if (file_exists($filePath)) {
            $yamlConfig = \Symfony\Component\Yaml\Yaml::parseFile($filePath);
            if (is_array($yamlConfig)) {
                self::$registry = $yamlConfig;
            }
        }
    }

    /**
     * Obtiene una instancia del driver para el canal especificado.
     *
     * @param string $channel
     * @param LoggerInterface|null $logger
     * @return SyncDriverInterface
     * @throws Exception
     */
    public static function get(string $channel, ?LoggerInterface $logger = null): SyncDriverInterface
    {
        self::loadRegistry();
        
        if (isset(self::$instances[$channel])) {
            return self::$instances[$channel];
        }

        if (!isset(self::$registry[$channel])) {
            throw new Exception("Driver not found for channel: $channel");
        }

        $config = self::$registry[$channel];
        $driverClass = $config['driver'];
        $authClass = $config['auth'];

        if (!class_exists($driverClass)) {
            throw new Exception("Driver class not found: $driverClass");
        }

        // Instanciación dinámica
        $channelConfig = \Helpers\Helpers::getChannelsConfig()[$channel] ?? [];
        $authProvider = new $authClass(null, $channelConfig);
        $driver = new $driverClass($authProvider, $logger);

        // Inject data processor if defined and supported by driver
        if (isset($config['processor']) && method_exists($driver, 'setDataProcessor')) {
            $driver->setDataProcessor($config['processor']);
        }

        self::$instances[$channel] = $driver;
        return $driver;
    }

    /**
     * Registra manualmente un nuevo driver (útil para extensiones externas).
     *
     * @param string $channel
     * @param string $driverClass
     * @param string $authClass
     */
    public static function register(string $channel, string $driverClass, string $authClass): void
    {
        self::loadRegistry();
        
        self::$registry[$channel] = [
            'driver' => $driverClass,
            'auth' => $authClass,
        ];
    }

    /**
     * Fuerza una instancia para un canal (útil para testing).
     *
     * @param string $channel
     * @param SyncDriverInterface $instance
     */
    public static function setInstance(string $channel, SyncDriverInterface $instance): void
    {
        self::$instances[$channel] = $instance;
    }

    /**
     * Obtiene la lista de canales que tienen un driver registrado.
     *
     * @return string[]
     */
    public static function getAvailableChannels(): array
    {
        self::loadRegistry();
        return array_keys(self::$registry);
    }
}



