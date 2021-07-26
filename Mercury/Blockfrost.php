<?php


namespace Mercury;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Blockfrost
{
    private $client;

    const endpoint = [
        "mainnet" => "https://cardano-mainnet.blockfrost.io/api/v0/",
        "testnet" => "https://cardano-testnet.blockfrost.io/api/v0/",
        "ipfs" => "https://ipfs.blockfrost.io/api/v0/"
    ];

    /**
     * Blockfrost constructor.
     * @param string $mode "testnet" or "mainnet"
     */
    public function __construct($mode)
    {
        $this->client = new Client([
            "base_uri" => self::endpoint[$mode]
        ]);
    }

    /**
     * @param string $uri The Blockfrost endpoint to query
     * @param false|array $params Array of query string/authentication parameters to pass
     */
    public function get($uri, $params = false): object
    {
        try {
            $response = $this->client->get($uri, $params);
            return (object)[
                'code' => $response->getStatusCode(),
                'data' => json_decode($response->getBody()->getContents()),
            ];
        } catch (GuzzleException $e) {
            return (object)[
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }

    }


}