<?php

namespace Mercury;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Pricefeeder {

    const hitbtc_url = 'https://api.hitbtc.com/api/2/public/ticker/ADAUSD';
    const coingecko_url = 'https://api.coingecko.com/api/v3/simple/price?ids=cardano&vs_currencies=usd';
    const bittrex_url = 'https://api.bittrex.com/v3/markets/ADA-USD/ticker';

    private static function getPrice($url) {
        $client = new Client();
        try {
            $response = $client->get($url);
        } catch (GuzzleException $e) {
            throw $e;
        }

        return json_decode($response->getBody()
                                    ->getContents());
    }

    private static function getHitBTCPrice() {
        try {
            $data = self::getPrice(self::hitbtc_url);
        } catch (GuzzleException $e) {
            throw $e;
        }

        return $data->last;

    }

    private static function getCoinGeckoPrice() {
        try {
            $data = self::getPrice(self::coingecko_url);
        } catch (GuzzleException $e) {
            throw $e;
        }

        return $data->cardano->usd;
    }

    private static function getBittrexPrice() {
        try {
            $data = self::getPrice(self::bittrex_url);
        } catch (GuzzleException $e) {
            throw $e;
        }

        return $data->lastTradeRate;
    }

    public static function getAveragePrice() {
        try {
            $hitbtc = self::getHitBTCPrice();
        } catch (GuzzleException $e) {
            throw $e;
        }

        try {
            $coingecko = self::getCoinGeckoPrice();
        } catch (GuzzleException $e) {
            throw $e;
        }

        try {
            $bittrex = self::getBittrexPrice();
        } catch (GuzzleException $e) {
            throw $e;
        }

        $prices = array_filter([
                                   $hitbtc,
                                   $coingecko,
                                   $bittrex,
                               ]);

        $avg_price = round(array_sum($prices) / count($prices), 6);

        return [
            'price'     => $avg_price,
            'hitbtc'    => $hitbtc,
            'coingecko' => $coingecko,
            'bittrex'   => $bittrex,
        ];

    }

}