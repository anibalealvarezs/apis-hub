<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\PostRequests;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class PostRequestsTest extends BaseUnitTestCase
{
    public function testSupportedChannels(): void
    {
        $channels = PostRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::facebook_organic, $channels);
    }

    public function testGetListFromFacebookOrganicExists(): void
    {
        $this->assertTrue(method_exists(PostRequests::class, 'getListFromFacebookOrganic'));
    }
}
