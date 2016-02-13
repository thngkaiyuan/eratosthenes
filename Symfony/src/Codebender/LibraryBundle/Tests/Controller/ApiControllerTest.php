<?php

namespace Codebender\LibraryBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Class ApiControllerTest
 * @package Codebender\LibraryBundle\Tests\Controller
 * @SuppressWarnings(PHPMD)
 */

class ApiControllerTest extends WebTestCase
{
    public function testGetExternalLibraryExamples()
    {
        $client = static::createClient();

        $authorizationKey = $client->getContainer()->getParameter('authorizationKey');

        $client = $this->postApiRequest(
            $client,
            $authorizationKey,
            '{"type":"getExamples", "version" : "1.0.0", "library" : "MultiIno"}'
        );

        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('multi_ino_example:methods', $response['examples']);
        $this->assertArrayHasKey('multi_ino_example', $response['examples']);
    }

    /**
     * Use this method for library manager API requests with POST data
     *
     * @param Client $client
     * @param string $authKey
     * @param string $data
     * @return Client
     */
    private function postApiRequest(Client $client, $authKey, $data)
    {
        $client->request(
            'POST',
            '/' . $authKey . '/v2',
            [],
            [],
            [],
            $data,
            true
        );

        return $client;
    }
}
