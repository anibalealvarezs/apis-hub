<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\AdRequests;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class AdRequestsTest extends BaseUnitTestCase
{
    public function testSupportedChannels(): void
    {
        $channels = AdRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::facebook, $channels);
    }
}
