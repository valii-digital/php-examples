<?php

namespace App\Services\Payments\Types;

use App\Facades\Balance;
use App\Jobs\SendTelegramError;
use App\Jobs\SendTelegramNotification;
use App\Jobs\WithdrawOrderToRandomWallet;
use App\Models\Container;
use App\Models\InternalTransaction;
use App\Models\Order;
use App\Models\PaymentCurrency;
use App\Models\PaymentType;
use App\Models\Payout;
use App\Models\User;
use App\Models\Withdraw;
use App\Models\WithdrawWallet;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use function Clue\StreamFilter\fun;

class Plisio extends Base
{

    const URL = 'https://plisio.net/api/v1/';
    const LIFETIME_MIN = 120;
//    const SAFE_COEF = 0.8;

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
        $response = $this->makeRequest('test-signature');

        return $response->success;
    }


    public function createInvoice(PaymentCurrency $currency, Order $order)
    {
        if (!$currency->enabled) {
            return false;
        }

        $calculatedAmount = $this->calculateAmount($currency, $order->amount);

        $webhookUrl = route('api.payment-callback', ['orderId' => $order->id]);

        $data = [
            "order_name" => "#" . $order->id . (!app()->environment('production') ? ' ' . app()->environment() : ''),
            "order_number" => floor(microtime(true) * 1000),
            'currency' => $currency->payment_slug,
            'amount' => $calculatedAmount,
            'callback_url' => $webhookUrl,
            'success_callback_url' => $webhookUrl,
            'fail_callback_url' => $webhookUrl,
            'expire_min' => self::LIFETIME_MIN,
//            'order_description' => 'plisio want this field, but before 6.02.2023 it works nice. Wht?'
        ];

        $response = $this->makeRequest('invoices/new', $data);

        if ($response->status == 'success') {
            $order->payment_expired_at = now()->addMinutes(self::LIFETIME_MIN);
            $order->payment_id = $response->data->txn_id;
            $order->currency_amount = $calculatedAmount;
            $order->save();
            return [
                'finished' => false,
                'address' => $response->data->wallet_hash,
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
        Log::info('plisio callback', $data);
        if (!isset($data['verify_hash'])) {
            return false;
        }
        if (!$this->verifyCallbackData($data)) {
            SendTelegramNotification::dispatch("Заказ #" . $order->id . " платежка долбаебы и криво подписывают запрос. Ждем фикс");
            Log::info('plisio request not valid', [$data, $data['verify_hash']]);
            return false;
        }
        $currency = PaymentCurrency::find($order->payment_currency_id);
        if (in_array($data['status'], [
            'pending'
        ])) {
            if ($data['pending_amount']) {
                $order->currency_received = $data['invoice_total_sum'] - $data['pending_amount'];
                $order->received = $order->currency_received * $currency->rate;
            }
        } elseif (in_array($data['status'], [
            'completed',
            'mismatch',
        ])) {
            $order->currency_received = round($data['amount'], 6);
            $order->received = round($order->currency_received * $currency->rate, 6);
            $order->transactions = $data['tx_urls'];
            if ($order->received > $order->amount) {
                $order->currency_amount = $order->currency_received;
                $order->amount = round($currency->rate * $order->currency_amount, 2);
            }

            $paymentSystemWallet = WithdrawWallet::where('payment_currency_id', $currency->id)->where('payment_system', 1)->first();
            if ($paymentSystemWallet) {
                $paymentSystemWallet->currency_amount += $data['invoice_sum'];
                $paymentSystemWallet->amount = $paymentSystemWallet->currency_amount * $currency->rate;
                $paymentSystemWallet->save();
            }
            $order->payment_success = true;


            $paymentSystemWallet = WithdrawWallet::where('payment_currency_id', $currency->id)->where('payment_system', 1)->first();

            if ($paymentSystemWallet) {
                $internalTransaction = new InternalTransaction([
                    'type' => InternalTransaction::TYPE_INCOME,
                    'amount' => $order->received,
                    'currency_amount' => $order->currency_received,
                    'comment' => 'income to Plisio by order ' . $order->id,
                    'payment_currency_id' => $currency->id,
                    'withdraw_wallet_id' => $paymentSystemWallet->id
                ]);
                $internalTransaction->save();
            }
        } else if (in_array($data['status'], [
            'expired',
            'error',
            'cancelled',
        ])) {
            if ($data['pending_amount'] > 0) {
                $order->currency_received = $data['invoice_total_sum'] - $data['pending_amount'];
                $order->received = $order->currency_received * $currency->rate;
                $container = Container::find($order->container_id);
                if ($order->received && $container->minimal_invest <= $order->received) {
                    $order->payment_expired = false;
                    $order->payment_success = true;
                    $order->amount = $order->received;
                    $order->currency_amount = $order->currency_received;
                    $order->partial_payment = true;
                    SendTelegramNotification::dispatch("Заказ #" . $order->id . " оплачен частично по истечении времени");
                } else {
                    $order->payment_expired = true;
                }
            } else {
                $order->payment_expired = true;
            }
        }
        $order->save();
        return true;
    }

    function getCurrencyRate(PaymentCurrency $currency): float
    {
        if ($currency->payment_slug == 'USDT_TRX' || $currency->payment_slug == 'BUSD') {
            return 1;
        }
        $currencies = Cache::remember('plisio_currencies', now()->addMinutes(30), function () {
            $data = $this->makeRequest('currencies');
            if ($data->status === 'success') {
                $currencies = [];
                foreach ($data->data as $curr) {
                    $currencies[$curr->currency] = $curr->price_usd;
                }
                return $currencies;
            }
            return null;
        });
        if ($currencies && isset($currencies[$currency->payment_slug])) {
            return $currencies[$currency->payment_slug];
        }
        return 0;
    }

    function verifyCallbackDataOld($hash, $data)
    {
        if (!isset($hash)) {
            return false;
        }
        unset($data['verify_hash']);
//        unset($data['order_description']);
        ksort($data);
        $postString = serialize($data);
        $checkKey = hash_hmac('sha1', $postString, $this->privateKey);
        if ($checkKey != $hash) {
            return false;
        }
        return true;
    }

    public function verifyCallbackData($data)
    {
        if (!isset($data['verify_hash'])) {
            return false;
        }

        if (isset($data['order_description']) && !$data['order_description']) {
            $data['order_description'] = '';
        }

        $verifyHash = $data['verify_hash'];
        unset($data['verify_hash']);
        ksort($data);
        if (isset($data['expire_utc'])) {
            $data['expire_utc'] = (string)$data['expire_utc'];
        }
        if (isset($data['tx_urls'])) {
            $data['tx_urls'] = html_entity_decode($data['tx_urls']);
        }
        $postString = serialize($data);
        $checkKey = hash_hmac('sha1', $postString, $this->privateKey);

        if ($checkKey != $verifyHash) {
            return false;
        }

        return true;
    }

    /**
     * @param array $data
     * @param string $method
     * @return mixed
     * @throws GuzzleException
     */
    private function makeRequest(string $method, array $data = []): mixed
    {
        $data['api_key'] = $this->privateKey;
        $url = self::URL . $method . '?' . http_build_query($data);
        $response = $this->client->get($url);
        return json_decode($response->getBody()->getContents());
    }


    public function withdrawToWallets(): array
    {
        $balances = $this->getBalances();
        if (count($balances)) {
            foreach ($balances as $currencyId => $balanceArray) {
                if (!$balanceArray['balance']) {
                    continue;
                }
                $currency = $balanceArray['currency'];
                $balance = $balanceArray['balance'];
                $wallet = WithdrawWallet::where('withdraw_from_payments', 1)->where('payment_currency_id', $currency->id)->where('payment_system', 0)->inRandomOrder()->first();
                $paymentSystemWallet = WithdrawWallet::where('payment_currency_id', $currency->id)->where('payment_system', 1)->first();
                if ($currency->rate > 10) {
                    $precision = 0;
                } else {
                    $precision = 6;
                }
                if (!$wallet || !$paymentSystemWallet) {
                    continue;
                }
                $nexPayoutSum = Payout::where('date', '>=', now()->startOfWeek(Carbon::MONDAY))->sum('total');
                $withdrawSum = $nexPayoutSum * 0.5;
                if ($withdrawSum > $balance) {
                    SendTelegramNotification::dispatch(
                        "Пополните баланс " . $currency->rate
                        . " на " . ($balance - $withdrawSum)
                        . ' Кошелек ' . $paymentSystemWallet->wallet
                    );
                    continue;
                } else {
                    $withdrawBalance = $balance - $withdrawSum;
                    $safeBalance = $withdrawSum;
                }
                $withdrawBalance = round($withdrawBalance, $precision);
                $safeBalance = $balance - $safeBalance;
                $response = $this->makeRequest('operations/withdraw', [
                    'currency' => $currency->payment_slug,
                    'type' => 'cash_out',
                    'to' => $wallet->wallet,
                    'amount' => $withdrawBalance
                ]);
                if ($response->status == 'success') {
                    $wallet->currency_amount += $balance;
                    $wallet->amount = $wallet->currency_amount * $currency->rate;
                    $wallet->save();

                    $paymentSystemWallet->currency_amount = $safeBalance;
                    $paymentSystemWallet->safe_balance = $safeBalance;
                    $paymentSystemWallet->amount = $wallet->currency_amount * $currency->rate;
                    $paymentSystemWallet->save();

                    $internalTransaction = new InternalTransaction([
                        'type' => InternalTransaction::TYPE_TRANSFER,
                        'amount' => $withdrawBalance * $currency->rate * -1,
                        'currency_amount' => $withdrawBalance,
                        'comment' => 'withdraw from Plisio(outcome)',
                        'payment_currency_id' => $currency->id,
                        'withdraw_wallet_id' => $wallet->id
                    ]);
                    $internalTransaction->save();

                    $internalTransaction = new InternalTransaction([
                        'type' => InternalTransaction::TYPE_TRANSFER,
                        'amount' => $withdrawBalance * $currency->rate,
                        'currency_amount' => $withdrawBalance,
                        'comment' => 'withdraw from Plisio(income)',
                        'payment_currency_id' => $currency->id,
                        'withdraw_wallet_id' => $wallet->id
                    ]);
                    $internalTransaction->save();
                }
            }
        }
        return $balances;
    }


    private function getBalances(): array
    {
        $currencies = PaymentCurrency::where('payment_type_id', $this->paymentType->id)->get();
        $balances = [];
        foreach ($currencies as $currency) {
            $balance = $this->getBalance($currency->payment_slug);
            if ($balance !== false) {
                $balances[$currency->id] = ['currency' => $currency, 'balance' => $balance];
            }
        }
        return $balances;
    }

    public function updateBalances(): void
    {
        $balances = $this->getBalances();
        foreach ($balances as $currencyId => $balanceArray) {
            $withdrawWallet = WithdrawWallet::where('payment_currency_id', $currencyId)
                ->where('payment_system', 1)->first();
            if ($withdrawWallet) {
                $balance = $balanceArray['balance'];
                $currency = $balanceArray['currency'];
                $withdrawWallet->currency_amount = $balance;
                $withdrawWallet->amount = $balance * $currency->rate;
                $withdrawWallet->save();
            }
        }
    }

    private function getBalance($currencySlug): float|bool
    {
        $data = $this->makeRequest('balances/' . $currencySlug);
        if ($data->status !== 'success') {
            return false;
        }
        return $data->data->balance;
    }

    public function checkWithdraw(Withdraw $withdraw): bool
    {
        $response = $this->makeRequest('operations/' . $withdraw->payment_id);
        if ($response->status == 'success') {
            if ($response->data->status == 'completed') {
                return true;
            } else {
                SendTelegramNotification::dispatch(
                    "Подтвержденный вывод был отменен " . $withdraw->id
                    . ' ' . $withdraw->txid
                    . ' ' . $withdraw->name
                );
                Log::warning('Ошибка проверки вывода', [
                    'response' => $response,
                    'withdraw' => $withdraw->currency
                ]);
                return false;
            }
        } else {
            SendTelegramNotification::dispatch(
                "Ошибка проверки вывода " . $withdraw->id
            );
            Log::warning('Ошибка проверки вывода', [
                'response' => $response,
                'withdraw' => $withdraw->id
            ]);
            throw new \Exception('checkWithdraw status != success');
        }
    }

    public function withdrawForUser(Withdraw $withdraw, string $wallet, WithdrawWallet $withdrawWallet): string|false
    {
        $currency = $withdraw->currency;
        $amount = $withdraw->currency_amount;
        try {
            $balance = $this->getBalance($currency->payment_slug);
            if (!$balance) {
                return false;
            }
            $withdrawWallet->currency_amount = $balance;
            $withdrawWallet->amount = $balance * $currency->rate;
            if ($balance > $amount) {

                $response = $this->makeRequest('operations/withdraw', [
                    'currency' => $currency->payment_slug,
                    'type' => 'cash_out',
                    'to' => $wallet,
                    'amount' => $amount
                ]);
                if ($response->status == 'success') {
                    if ($response->data->tx_id) {
                        if (is_array($response->data->tx_id)) {
                            $txid = $response->data->tx_id[0];
                        } else {
                            $txid = $response->data->tx_id;
                        }
                        $withdraw->update(['payment_id' => $response->data->id]);
                        return $txid;
                    }
                } else {
                    SendTelegramNotification::dispatch("Ошибка вывода " . $withdraw->id . ' ' . $currency->name . ': ' . $response->data->message);
                }
                Log::warning('plisio withdraw error', [
                    'response' => $response
                ]);
            } else {
                SendTelegramNotification::dispatch(
                    "Ошибка вывода " . $withdraw->id
                    . $currency->name . ': недостаточно средств на балансе '
                    . $balance . '/' . $amount . " Пополните кошелек " . $withdrawWallet->wallet
                );
            }
            return false;
        } catch (ClientException $exception) {
            $response = $exception->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            $message = json_decode($responseBodyAsString)?->data?->message;
            if ($message == 'toBn number must be valid hex string.') {
                Balance::declineWithdraw($withdraw);
                SendTelegramNotification::dispatch('Ошибка вывода ' . $withdraw->id . ' - кошелек не валиден');
            } else {
                $telegramError = 'Ошибка вывода ' . $withdraw->id;
                $telegramError .= $message;
                Log::warning('plisio withdraw error', [
                    'response' => $responseBodyAsString,
                    'response_status' => $response->getStatusCode()
                ]);
                SendTelegramNotification::dispatch($telegramError);
            }
            return false;
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
            $statusCode = $response->getStatusCode();
            $responseBodyAsString = $response->getBody()->getContents();
            $telegramError = 'Ошибка вывода ' . $withdraw->id . ', status ' . $statusCode;
            $telegramError .= json_decode($responseBodyAsString)?->data?->message;
            Log::warning('plisio withdraw error', [
                'response' => $responseBodyAsString
            ]);
            SendTelegramNotification::dispatch($telegramError);
            return false;
        }
    }
}
