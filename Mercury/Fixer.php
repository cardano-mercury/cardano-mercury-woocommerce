<?php

namespace Mercury;

if (!defined('ABSPATH')) {
    die();
}

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Fixer {

    private $apikey;
    private $client;

    const uri = 'https://api.apilayer.com/fixer/';
    const log = ['source' => 'mercury_fixer_error'];

    public function __construct($apikey) {
        $this->apikey = $apikey;
        $this->client = new Client([
                                       'base_uri' => self::uri,
                                       'headers'  => [
                                           'apikey' => $this->apikey,
                                       ],
                                   ]);
    }

    public function convert($base = 'EUR', $to = 'USD'): object {
        try {
            $response = $this->client->get('latest', [
                'query' => [
                    'base'    => $base,
                    'symbols' => $to,
                ],
            ]);

            return (object)[
                'code' => $response->getStatusCode(),
                'data' => json_decode($response->getBody()
                                               ->getContents()),
            ];
        } catch (GuzzleException $e) {
            $log = wc_get_logger();
            $log->debug("Fixer.io Currency API Error: " . wc_print_r($e, true), self::log);

            return (object)[
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

}