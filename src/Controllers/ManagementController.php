<?php

namespace Controllers;

use Exception;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ManagementController extends BaseController
{
    /**
     * Updates the .env file with new credentials (Phase 2).
     */
    public function updateCredentials(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('management.log');
            $data = json_decode($request->getContent(), true);

            if (empty($data)) {
                return new Response(json_encode(['error' => 'Empty request body']), 400, ['Content-Type' => 'application/json']);
            }

            $envFileName = getenv('ENV_FILE') ?: '.env';
            $envPath = realpath(__DIR__ . '/../../' . $envFileName);
            
            if (!$envPath || !file_exists($envPath)) {
                return new Response(json_encode(['error' => "Environment file '$envFileName' not found"]), 500, ['Content-Type' => 'application/json']);
            }

            $currentEnv = file_get_contents($envPath);
            $updatedEnv = $currentEnv;

            // List of system-level allowed credential updates
            $allowedKeys = [
                'APP_API_KEY',
                'MONITOR_FACADE_URL',
                'MONITOR_TOKEN'
            ];

            // Merge with channel-specific credentials from drivers
            foreach (\Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getAvailableChannels() as $channel) {
                try {
                    $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channel);
                    $allowedKeys = array_merge($allowedKeys, $driver->getUpdatableCredentials());
                } catch (Exception $e) {
                    $logger->warning("Could not load credentials for channel $channel: " . $e->getMessage());
                }
            }

            $allowedKeys = array_unique($allowedKeys);

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedKeys)) {
                    $logger->info("Updating credential: {$key}");
                    
                    // Simple regex replacement for .env format
                    $pattern = "/^{$key}=.*/m";
                    $replacement = "{$key}={$value}";
                    
                    if (preg_match($pattern, $updatedEnv)) {
                        $updatedEnv = preg_replace($pattern, $replacement, $updatedEnv);
                    } else {
                        // If key doesn't exist, append it
                        $updatedEnv .= "\n{$key}={$value}";
                    }
                }
            }

            file_put_contents($envPath, $updatedEnv);

            return new Response(json_encode(['success' => true, 'message' => 'Credentials updated successfully']), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Triggers a remote deployment (Phase 3).
     */
    public function triggerRedeploy(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('management.log');
            $logger->info("Deployment trigger received from Facade");

            $isDemo = str_contains(strtolower(getenv('PROJECT_NAME') ?: ''), 'demo');
            $scriptName = $isDemo ? 'full-deploy-demo.sh' : 'full-deploy.sh';
            $deployScript = realpath(__DIR__ . "/../../bin/$scriptName");

            if (!$deployScript) {
                return new Response(json_encode(['error' => "Deployment script ($scriptName) not found"]), 500, ['Content-Type' => 'application/json']);
            }

            // Using bash explicitly to ensure compatibility
            $command = "nohup bash \"$deployScript\" > /dev/null 2>&1 &";
            exec($command);

            return new Response(json_encode(['success' => true, 'message' => 'Redeployment triggered in background']), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Reports server/instance status back to Facade (Infrastructure focused).
     */
    public function getStatus(): Response
    {
        try {
            $data = \Services\HealthService::getInfraStatus();
            return new Response(json_encode(['success' => true, 'data' => $data]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Performs an action on a specific container via Docker socket.
     */
    public function containerAction(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('management.log');
            $data = json_decode($request->getContent(), true);
            $name = $data['name'] ?? null;
            $action = $data['action'] ?? null;

            if (!$name || !in_array($action, ['start', 'stop', 'restart'])) {
                return new Response(json_encode(['error' => 'Invalid container name or action']), 400, ['Content-Type' => 'application/json']);
            }

            $logger->info("Container Action: {$action} on {$name}");
            
            // Execute docker command via the mounted socket
            $command = "docker {$action} {$name} 2>&1";
            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode !== 0) {
                return new Response(json_encode([
                    'success' => false, 
                    'error' => implode("\n", $output)
                ]), 500, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => true, 'message' => "Container {$name} {$action}ed successfully"]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Records a comprehensive node heartbeat (Business focused).
     */
    public function getHeartbeat(): Response
    {
        try {
            $data = \Services\HealthService::getFullHealthReport();
            return new Response(json_encode(['success' => true, 'data' => $data]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Performs a TOTAL reset of a specific channel (Atomic Cleanup).
     */
    public function resetChannel(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('management.log');
            $data = json_decode($request->getContent(), true);
            $channel = $data['channel'] ?? null;

            if (!$channel) {
                return new Response(json_encode(['error' => 'Missing channel parameter']), 400, ['Content-Type' => 'application/json']);
            }

            $logger->info("Atomic Channel Reset Triggered via API for: {$channel}");
            
            // Execute the CLI command synchronously
            // We use the full path to bin/cli.php for reliability
            $cliPath = realpath(__DIR__ . '/../../bin/cli.php');
            $command = "php \"$cliPath\" app:reset-channel --channel=\"$channel\" --no-interaction 2>&1";
            
            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode !== 0) {
                return new Response(json_encode([
                    'success' => false, 
                    'error' => implode("\n", $output)
                ]), 500, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => true, 'message' => "Channel {$channel} reset perfectly"]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }
}
