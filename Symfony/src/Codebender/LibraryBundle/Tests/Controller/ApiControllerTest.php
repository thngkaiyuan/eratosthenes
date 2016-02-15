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
    /**
     * Test for the getExamples API
     */
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
     * Test for the getExampleCode API
     */
    public function testGetExampleCode()
    {
        $client = static::createClient();

        $authorizationKey = $client->getContainer()->getParameter('authorizationKey');

        // Get first example
        $client = $this->postApiRequest(
            $client,
            $authorizationKey,
            '{"type":"getExampleCode","library":"SubCateg", "version": "1.0.0", "example":"subcateg_example_one"}'
        );

        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($response['success']);
        $this->assertEquals('subcateg_example_one.ino', $response['files'][0]['filename']);
        $this->assertContains('void setup()', $response['files'][0]['code']);

        // Get second example
        $client = $this->postApiRequest(
            $client,
            $authorizationKey,
            '{"type":"getExampleCode","library":"SubCateg", "version": "1.0.0", "example":"experienceBased:Beginners:subcateg_example_two"}'
        );

        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($response['success']);
        $this->assertEquals('subcateg_example_two.ino', $response['files'][0]['filename']);
        $this->assertContains('void setup()', $response['files'][0]['code']);
    }

    /**
     * This method tests the getVersions API.
     */
    public function testGetVersions()
    {
        // Test successful getVersions calls
        $this->assertSuccessfulGetVersions('default', ['1.0.0', '1.1.0']);
        $this->assertSuccessfulGetVersions('DynamicArrayHelper', ['1.0.0']);
        $this->assertSuccessfulGetVersions('HtmlLib', []);

        // Test invalid getVersions calls
        $this->assertFailedGetVersions('nonExistentLib');
        $this->assertFailedGetVersions('');
        $this->assertFailedGetVersions(null);
    }

    /**
     * This method test the getKeywords API.
     */
    public function testGetKeywords()
    {
        $client = static::createClient();

        $authorizationKey = $client->getContainer()->getParameter('authorizationKey');

        $client = $this->postApiRequest($client, $authorizationKey, '{"type":"getKeywords", "library":"EEPROM"}');

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(true, $response['success']);
        $this->assertArrayHasKey('keywords', $response);
        $this->assertArrayHasKey('KEYWORD1', $response['keywords']);
        $this->assertEquals('EEPROM', $response['keywords']['KEYWORD1'][0]);
    }

    /**
     * This method checks if the response of a single getVersions
     * API call is successful and returns the correct versions.
     *
     * @param $defaultHeader
     * @param $expectedVersions
     */
    private function assertSuccessfulGetVersions($defaultHeader, $expectedVersions)
    {
        $response = $this->postGetVersionsApi($defaultHeader);
        $this->assertEquals(true, $response['success']);
        $this->assertArrayHasKey('versions', $response);
        $this->assertTrue($this->areSimilarArrays($expectedVersions, $response['versions']));
    }

    /**
     * This method checks if the response of a single getVersions
     * API call is unsuccessful.
     *
     * @param $defaultHeader
     */
    private function assertFailedGetVersions($defaultHeader)
    {
        $response = $this->postGetVersionsApi($defaultHeader);
        $this->assertEquals(false, $response['success']);
    }

    /**
     * This method checks if two arrays, $array1 and $array2,
     * has the same elements.
     *
     * @param $array1
     * @param $array2
     * @return bool
     */
    private function areSimilarArrays($array1, $array2)
    {
        $arrayDiff1 = array_diff($array1, $array2);
        $arrayDiff2 = array_diff($array2, $array1);
        $totalDifferences = array_merge($arrayDiff1, $arrayDiff2);

        return empty($totalDifferences);
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

    /**
     * This method submits a POST request to the getVersions API
     * and returns its response.
     *
     * @param $defaultHeader
     * @return $response
     */
    private function postGetVersionsApi($defaultHeader)
    {
        $client = static::createClient();
        $authorizationKey = $client->getContainer()->getParameter('authorizationKey');
        $client = $this->postApiRequest($client, $authorizationKey, '{"type":"getVersions","library":"' . $defaultHeader . '"}');
        $response = json_decode($client->getResponse()->getContent(), true);
        return $response;
    }
}
