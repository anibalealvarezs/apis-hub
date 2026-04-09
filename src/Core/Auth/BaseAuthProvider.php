<?php

declare(strict_types=1);

namespace Core\Auth;

use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Exception;

abstract class BaseAuthProvider implements AuthProviderInterface
{
    protected string $tokenPath;
    protected array $data = [];

    public function __construct(string $tokenPath = "")
    {
        $this->tokenPath = $tokenPath;
        $this->load();
    }

    protected function load(): void
    {
        if ($this->tokenPath && file_exists($this->tokenPath)) {
            $content = file_get_contents($this->tokenPath);
            if ($content) {
                $this->data = json_decode($content, true) ?: [];
            }
        }
    }

    protected function save(): void
    {
        if ($this->tokenPath) {
            $dir = dirname($this->tokenPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->tokenPath, json_encode($this->data, JSON_PRETTY_PRINT));
        }
    }

    public function isValid(): bool
    {
        return !empty($this->getAccessToken());
    }

    public function refresh(): bool
    {
        // Default implementation does nothing, override if needed
        return true;
    }

    public function getScopes(): array
    {
        return [];
    }
}
