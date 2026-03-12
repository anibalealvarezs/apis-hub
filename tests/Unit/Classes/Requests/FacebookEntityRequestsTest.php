<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\FacebookEntityRequests;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class FacebookEntityRequestsTest extends BaseUnitTestCase
{
    public function testSupportedChannels(): void
    {
        $channels = FacebookEntityRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::facebook, $channels);
    }

    public function testGetListFromFacebookExists(): void
    {
        $this->assertTrue(method_exists(FacebookEntityRequests::class, 'getListFromFacebook'));
    }
}
