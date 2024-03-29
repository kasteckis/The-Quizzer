<?php


namespace App\Tests\SmokeTests;


use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

class ResultsControllerTest extends TestCase
{
    private Client $client;

    public function setUp(): void
    {
        $this->client = new Client([
            'base_uri' => $_ENV['LOCAL_WEB_DEV_IP'],
            'http_errors' => false
        ]);
    }

    public function testResultsEndpoint(): void
    {
        $response = $this->client->request('GET', '/rezultatai');

        $this->assertEquals(200, $response->getStatusCode());
    }
}
