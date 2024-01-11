<?php

namespace App\Services\Payments\Types;

use App\Jobs\WithdrawOrderToRandomWallet;
use App\Models\InternalTransaction;
use App\Models\Order;
use App\Models\PaymentCurrency;
use App\Models\PaymentType;
use App\Models\WithdrawWallet;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Onchainpay extends Base
{

    const URL = 'https://ocp.onchainpay.io/api-gateway/';
    const LIFETIME = 7200;

    private Client $client;

    public function __construct(
        private string|null $publicKey,
        private string|null $privateKey,
        private string|null $advancedBalance,
        private PaymentType $paymentType
    )
    {
        parent::__construct($publicKey, $privateKey, $advancedBalance, $paymentType);
        $this->client = new Client();
    }

    /**
     * @return bool
     */
    public function test(): bool
    {
        $this->withdrawToWallets();
        $response = $this->makeRequest([], 'test-signature');

        return $response->success;
    }

    public function withdrawToWallets(): array
    {
        $addresses = $this->addresses();
        $withdraws = [];
        if ($addresses) {
            $wallets = WithdrawWallet::where('withdraw_from_payments', 1)->with('currency')->get()->keyBy('id');
            $walletsData = [];
            foreach ($wallets as $wallet) {
                $key = $wallet->currency->slug . '-' . $wallet->currency->network;
                if (!isset($wallets[$key])) {
                    $walletsData[$key] = [$wallet];
                } else {
                    $walletsData[] = $wallet;
                }
            }
            $currencies = PaymentCurrency::all()->keyBy('id');
            if (count($walletsData) == 0) {
                return $withdraws;
            }
            foreach ($addresses as $address) {
                if ($address->balance) {
                    $key = $address->currency . '-' . $address->network;
                    if (isset($walletsData[$key])) {
                        $randomKey = rand(0, count($walletsData[$key]) - 1);
                        $withdrawAddress = $walletsData[$key][$randomKey]->wallet;
                        $id = $walletsData[$key][$randomKey]->id;
                        if ($this->withdraw($address->id, $withdrawAddress, $address->balance)) {
                            if (isset($withdraw[$withdrawAddress])) {
                                $withdraws[$withdrawAddress] += $address->balance;
                            } else {
                                $withdraws[$withdrawAddress] = $address->balance;
                            }
                            $wallets[$id]->currency_amount += $address->balance;
                            $currencyId = $wallets[$id]->payment_currency_id;
                            $internalTransaction = new InternalTransaction([
                                'type' => InternalTransaction::TYPE_INCOME,
                                'amount' => $address->balance * $currencies[$currencyId]->rate,
                                'currency_amount' => $address->balance,
                                'comment' => 'withdraw from Onchainpay',
                                'withdraw_wallets' => $wallets[$id]->id,
                                'payment_currency_id' => $currencyId,
                                'withdraw_wallet_id' => $id
                            ]);
                            $internalTransaction->save();
                        }

                    }
                }
            }

            foreach ($wallets as $wallet) {
                $wallet->save();
            }

        }
        return $withdraws;
    }


    public function withdrawOrderToWallet(Order $order): bool
    {
        $wallet = WithdrawWallet::where('withdraw_from_payments', 1)->where('payment_currency_id', $order->payment_currency_id)->inRandomOrder()->first();

        if ($wallet && $this->withdraw($order->withdraw_address_id, $wallet->wallet, $order->received)) {
            $wallet->currency_amount += $order->received;
            $wallet->save();

            $internalTransaction = new InternalTransaction([
                'type' => InternalTransaction::TYPE_INCOME,
                'amount' => $order->amount,
                'currency_amount' => $order->received,
                'comment' => 'withdraw order ' . $order->id . ' from Onchainpay',
                'withdraw_wallets' => $wallet->id,
                'payment_currency_id' => $wallet->payment_currency_id,
                'withdraw_wallet_id' => $wallet->id
            ]);
            $internalTransaction->save();
            return true;
        }

        return false;

    }

    public function createInvoice(PaymentCurrency $currency, Order $order)
    {
        if (!$currency->enabled) {
            return false;
        }

        $calculatedAmount = $this->calculateAmount($currency, $order->amount);

        $webhookUrl = route('api.payment-callback', ['orderId' => $order->id]);
        $data = [
            "advancedBalanceId" => $this->advancedBalance,
            "currency" => $currency->slug,
            "network" => $currency->network,
            "amount" => $calculatedAmount,
            "order" => "#" . $order->id . (!app()->environment('production') ? ' ' . app()->environment() : ''),
            "lifetime" => self::LIFETIME,
            "description" => "Order #" . $order->id,
            "successWebhook" => $webhookUrl,
            "errorWebhook" => $webhookUrl,
            "returnUrl" => config('app.front_url') . '/success/' . $order->id,
        ];


        $response = $this->makeRequest($data, 'make-order');

        Log::info('CREATE INVOICE', [
            'request' => $data,
            'response' => $response
        ]);
        if ($response->success) {
            $order->payment_expired_at = now()->addSeconds(self::LIFETIME);
            $order->payment_id = $response->response->orderId;
            $order->currency_amount = $calculatedAmount;
            $order->withdraw_address_id = $response->response->addressId;
            $order->save();
            return [
                'finished' => false,
                'address' => $response->response->address,
                'currency_amount' => $calculatedAmount,
                'expiresAt' => $order->payment_expired_at,
                'order_id' => $order->id,
                'currency_slug' => $currency->slug,
                'currency_name' => $currency->name
            ];
        }
    }

    private function calculateAmount(PaymentCurrency $currency, float $amount)
    {
        return number_format($amount / $currency->rate, 6, '.', '');
    }

    public function callback(Request $request, Order &$order)
    {
        $data = $request->all();
        $sign = $request->header('x-api-signature');
        if ($sign && $this->checkSign($request->getContent(), $sign)) {
            if ($data['id'] == $order->payment_id) {
                $order->transactions = $data['transactions'];
                $received = 0;
                $currency = PaymentCurrency::find($order->payment_currency_id);
                if ($order->transactions && count($order->transactions)) {
                    foreach ($order->transactions as $transaction) {
                        if ($transaction['status'] == 'processed') {
                            $received += $transaction['amount'];
                        }
                    }
                    $order->currency_received = round($received, 6);
                    $order->received = round($order->currency_received * $currency->rate, 6);
                    if ($order->currency_received > $order->currency_amount) {
                        $order->currency_amount = round($order->received, 6);
                        $order->amount = $order->received;
                    }
                }


                if ($data['status'] === 'error' || $data['status'] === 'rejected') {
                    $order->payment_expired = true;
                }


                if ($data['status'] === 'processed') {
                    $order->payment_success = true;
//                    dispatch(new WithdrawOrderToRandomWallet($order))->delay(Carbon::now()->addMinutes(5));
                }
                $order->save();
                return true;
            }
        } else {
            Log::warning('Wrong callback SIGN', [
                'sign' => $sign,
                'correct_sign', $this->makeSign($data),
                'headers' => $request->headers,
                'data' => $data
            ]);
        }
        return false;
    }

    private function addresses()
    {
        $data = [
            "advancedBalanceId" => $this->advancedBalance,
        ];
        $response = $this->makeRequest($data, 'account-addresses');
        if ($response->success) {
            return $response->response;
        }
        return false;
    }


    private function withdraw(string $addressId, string $address, float $amount)
    {
        try {
            $data = [
                "advancedBalanceId" => $this->advancedBalance,
                "addressId" => $addressId,
            ];
            $response = $this->makeRequest($data, 'fee-token');
            if ($response->success) {
                Log::info('fee token', [
                    'fee' => $response
                ]);
                $data = [
                    "advancedBalanceId" => $this->advancedBalance,
                    "addressId" => $addressId,
                    "address" => $address,
                    "amount" => number_format($amount, 6, '.', ''),
                    "feeToken" => $response->response->token,
//                "tag" => "exercitation magn",
                ];

                $response = $this->makeRequest($data, 'make-withdrawal');
                Log::info('withdraw request', ['response' => $response]);
                if ($response->success) {
                    return true;
                }
            }
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            Log::warning('WITHDRAW API ERROR', [
                'response' => $responseBodyAsString,
                'message' => $e->getMessage()
            ]);
            return 0;
        }
        return false;
    }

    public function getCurrencyRate(PaymentCurrency $currency): float
    {
        $slug = $currency->slug;
        if ($slug == 'USDT' || $slug == 'BUSD') {
            return 1;
        }
        $from = 'USDT';
        try {
            return $this->getPriceRates($slug, $from);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            Log::warning($e->getMessage(), [
                'response' => $responseBodyAsString,
                'message' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function availableCurrencies()
    {
        $data = [];
        $response = $this->makeRequest($data, 'available-currencies');
        if ($response->success) {
            $currencies = [];
            foreach ($response->response as $currency) {
                if ($currency->allowDeposit && $currency->allowWithdrawal) {
                    $networks = [];
                    foreach ($currency->networks as $network) {
                        if ($network->allowDeposit && $network->allowWithdrawal) {
                            $networks[] = [
                                'name' => $network->name,
                                'alias' => $network->alias
                            ];
                        }
                    }
                    $currencies[] = [
                        'name' => $currency->currency,
                        'alias' => $currency->alias,
                        'networks' => $networks
                    ];
                }
            }
            return $currencies;
        }
        return [];
    }

    private function getPriceRates(string $from, string $to = 'USDT')
    {
        $response = $this->makeRequest([
            'from' => $from,
            'to' => $to
        ], 'price-rate');
        if ($response->success) {
            return $response->response;
        }
        return [];
    }


    private function advancedBalances()
    {
        $response = $this->makeRequest([], 'advanced-balances');
        if ($response->success) {
            return $response->response;
        }
        return false;
    }

    /**
     * @param array $data
     * @param string $method
     * @return mixed
     * @throws GuzzleException
     */
    private function makeRequest(array $data, string $method): mixed
    {
        $data['nonce'] = floor(microtime(true) * 1000);
        $response = $this->client->post(self::URL . $method, [
            'json' => $data,
            'headers' => [
                'x-api-public-key' => $this->publicKey,
                'x-api-signature' => $this->makeSign($data)
            ]
        ]);

        return json_decode($response->getBody()->getContents());
    }

    private function makeSign($data): string
    {
        $json = json_encode($data);
        return $this->makeSignFromJson($json);
    }

    private function makeSignFromJson(string $json): string
    {
        return hash_hmac('SHA256', $json, $this->privateKey);
    }

    private function checkSign(string $json, $testSign): bool
    {
        $ourSign = $this->makeSignFromJson($json);
        if (hash_equals($testSign, $ourSign)) {
            return true;
        }
        return false;
    }
}
