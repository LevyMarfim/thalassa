<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthzReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok'],
            json_decode($client->getResponse()->getContent(), true)
        );
    }
}
