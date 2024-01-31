<?php

namespace Mercury;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TapTools {

    const valid_timeframes = [
        "1h",
        "4h",
        "12h",
        "24h",
        "7d",
        "30d",
        "180d",
        "1y",
        "all",
    ];

    const cron_log = ['source' => 'mercury_cron'];

    private $client;

    const endpoint = [
        "mainnet" => "https://openapi.taptools.io/api/v1/",
    ];

    public function __construct($key) {
        $this->client = new Client([
            "base_uri" => self::endpoint['mainnet'],
            "headers"  => [
                "x-api-key" => $key,
            ],
        ]);
    }

    /**
     * @param $params
     *
     * @return object
     */
    private function get($uri, $params = []): object {
        try {
            $response = $this->client->get($uri, $params);

            return (object)[
                'code' => $response->getStatusCode(),
                'data' => json_decode($response->getBody()
                                               ->getContents()),
            ];
        } catch (GuzzleException $e) {
            return (object)[
                'code'    => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function post($uri, $params): object {
        try {
            $response = $this->client->post($uri, $params);

            return (object)[
                'code' => $response->getStatusCode(),
                'data' => json_decode($response->getBody()
                                               ->getContents()),
            ];
        } catch (GuzzleException $e) {
            return (object)[
                'code'    => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param string $timeframe
     * @param int    $page
     * @param int    $perPage
     *
     * @return object
     * @throws Exception
     */
    public function getTopVolume(string $timeframe = "30d", int $page = 1, int $perPage = 20): object {
        if (!in_array($timeframe, self::valid_timeframes)) {
            throw new Exception("Invalid Timeframe!");
        }

        return $this->get("token/top/volume", ['query' => compact('timeframe', 'page', 'perPage')]);
    }

    public function getTokenPrices(array $units): object {
        $params = [
            'json' => $units,
        ];

        return $this->post("token/prices", $params);
    }

    public function getTokenVolume(string $unit): object {
        return $this->get("token/ohlcv", [
            "query" => [
                'unit'         => $unit,
                'interval'     => '1M',
                'numIntervals' => 1,
            ],
        ]);
    }

}