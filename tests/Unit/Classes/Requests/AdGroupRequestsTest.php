<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\AdGroupRequests;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class AdGroupRequestsTest extends BaseUnitTestCase
{
    public function testSupportedChannels(): void
    {
        $channels = AdGroupRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::facebook, $channels);
    }

    public function testGetListFromFacebookExists(): void
    {
        $this->assertTrue(method_exists(AdGroupRequests::class, 'getListFromFacebook'));
    }
}
