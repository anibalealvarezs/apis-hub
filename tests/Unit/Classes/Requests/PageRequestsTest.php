<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\PageRequests;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class PageRequestsTest extends BaseUnitTestCase
{
    public function testSupportedChannels(): void
    {
        $channels = PageRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::facebook_organic, $channels);
    }

    public function testGetListFromFacebookOrganicExists(): void
    {
        $this->assertTrue(method_exists(PageRequests::class, 'getListFromFacebookOrganic'));
    }
}
