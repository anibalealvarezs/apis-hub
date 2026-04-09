<?php

declare(strict_types=1);

namespace Core\Auth;

class KlaviyoAuthProvider extends BaseAuthProvider
{
    public function getAccessToken(): string
    {
        return $this->data['klaviyo_auth']['access_token'] ?? "";
    }

    public function setAccessToken(string $token): void
    {
        if (!isset($this->data['klaviyo_auth'])) {
            $this->data['klaviyo_auth'] = [];
        }
        $this->data['klaviyo_auth']['access_token'] = $token;
        $this->save();
    }
}
