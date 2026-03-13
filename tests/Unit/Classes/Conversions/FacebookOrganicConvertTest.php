<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\FacebookOrganicConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Tests\Unit\BaseUnitTestCase;

class FacebookOrganicConvertTest extends BaseUnitTestCase
{
    public function testPages(): void
    {
        $platformId = '111222333';
        $name = 'Test Page';
        $accountId = 1;

        $data = [
            [
                'id' => $platformId,
                'name' => $name,
                'access_token' => 'dummy_token',
                'category' => 'Test Category',
            ]
        ];

        $result = FacebookOrganicConvert::pages($data, $accountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $page = $result->first();

        $this->assertEquals($platformId, $page->platformId);
        $this->assertEquals($name, $page->title);
        $this->assertEquals($accountId, $page->accountId);
    }

    public function testPosts(): void
    {
        $platformId = '777888999';
        $message = 'Test Post Message';
        $pageId = 123;
        $accountId = 1;

        $data = [
            [
                'id' => $platformId,
                'message' => $message,
                'created_time' => '2024-01-01T00:00:00+0000',
                'permalink_url' => 'https://facebook.com/post/1',
            ]
        ];

        $result = FacebookOrganicConvert::posts($data, $pageId, $accountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $post = $result->first();

        $this->assertEquals($platformId, $post->platformId);
        $this->assertEquals($pageId, $post->pageId);
    }
}
