<?php

namespace Mercury;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Blockfrost {

    private $client;

    const endpoint = [
        "mainnet" => "https://cardano-mainnet.blockfrost.io/api/v0/",
        "preprod" => "https://cardano-preprod.blockfrost.io/api/v0/",
        "preview" => "https://cardano-preview.blockfrost.io/api/v0/",
        "testnet" => "https://cardano-preprod.blockfrost.io/api/v0/",
        "ipfs"    => "https://ipfs.blockfrost.io/api/v0/",
    ];

    public static function new(): self {
        $options = get_option('woocommerce_cardano_mercury_settings');
        $mode    = $options['blockfrostMode'];
        $key     = $options['blockfrostAPIKey'];

        return new self($mode, $key);
    }

    /**
     * Blockfrost constructor.
     *
     * @param string $mode "testnet" or "mainnet"
     * @param string $key  The project API key
     */
    public function __construct(string $mode, string $key = '') {
        $this->client = new Client([
            "base_uri" => self::endpoint[$mode],
            "headers"  => [
                "project_id" => $key,
            ],
        ]);

        return $this;
    }

    /**
     * @param string $uri    The Blockfrost endpoint to query
     * @param array  $params Array of query string/authentication parameters to pass
     */
    public function get($uri, $params = []): object {
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

    public function isHealthy() {
        $result = $this->get("health");

        return $result->data->is_healthy;
    }

    public function getTransactions($address, $page = 1, $order = 'desc'): object {
        return $this->get("addresses/{$address}/transactions", [
            'query' => compact('page', 'order'),
        ]);
    }

    public function getTransactionUTxO($hash): object {
        return $this->get("txs/{$hash}/utxos");
    }

    public function getTransactionMetadata($hash):object {
        return $this->get("txs/{$hash}/metadata");
    }

    public function getAddressUTXO($address, $page = 1) {
        return $this->get("addresses/{$address}/utxos", [
            'query' => [
                'page'  => $page,
                'order' => 'desc',
            ],
        ]);
    }

    public function getAllAddressUTXO($address) {

        $page = 1;
        $utxo = [];

        $last_page = false;

        while (!$last_page) {
            $utxo_set = $this->getAddressUTXO($address, $page);
            if ($utxo_set->code === 500) {
                echo "Error fetching UTXO? {$utxo_set->message}";
                break;
            }
            if (empty($utxo_set->data) or count($utxo_set->data) < 100) {
                $last_page = true;
            }
            $utxo = array_merge($utxo, $utxo_set->data);
            $page++;
        }

        return $utxo;

    }

}