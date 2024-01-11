<?php

namespace App\Services\Payments;

use App\Facades\Balance;
use App\Jobs\SendTelegramNotification;
use App\Models\Order;
use App\Models\PaymentCurrency;
use App\Models\PaymentType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\WithdrawWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Payment
{
    public function createInvoice(
        PaymentCurrency $currency,
        Order           $order
    ): mixed
    {
        $paymentType = PaymentType::find($currency->payment_type_id);
        $service = $this->makeType($paymentType);
        return $service->createInvoice($currency, $order);
    }

    public function test(
        PaymentType $paymentType,
    ): bool
    {
        $service = $this->makeType($paymentType);
        return $service->test();
    }


    public function withdrawForUser(
        PaymentType $paymentType,
        Withdraw    $withdraw
    ): false|array
    {
        $service = $this->makeType($paymentType);
        $wallet = $withdraw->wallet;

        $withdrawWallet = WithdrawWallet::where('payment_currency_id', $withdraw->payment_currency_id)
            ->where('payment_system', 1)->first();

        if ($wallet && $withdrawWallet) {
            $txid = $service->withdrawForUser($withdraw, $wallet->address, $withdrawWallet);
            $withdrawWallet->save();
            if ($txid) {
                SendTelegramNotification::dispatch("Успешный вывод " . $withdraw->currency_amount . ' ' . $withdraw->currency->name);
                return [
                    'wallet' => $withdrawWallet,
                    'txid' => $txid
                ];
            }
        }
        return false;
    }

    public function withdrawToWallets(
        PaymentType $paymentType,
    ): array
    {
        $service = $this->makeType($paymentType);
        return $service->withdrawToWallets();
    }

    public function withdrawOrderToWallet(
        PaymentType $paymentType,
        Order       $order
    ): bool
    {
        $service = $this->makeType($paymentType);
        return $service->withdrawOrderToWallet($order);
    }

    public function checkWithdraw(PaymentType $paymentType, Withdraw $withdraw): bool
    {
        $service = $this->makeType($paymentType);
        if ($service->checkWithdraw($withdraw)) {
            $withdraw->update(['checked_at' => now()]);
            return true;
        } else {
            $withdraw->update(['moderated' => false, 'moderated_at' => null]);
            Balance::declineWithdraw($withdraw);
            return false;
        }
    }

    public function updateCurrenciesRate(
        PaymentType $paymentType,
    ): void
    {
        $service = $this->makeType($paymentType);
        $currencies = PaymentCurrency::where('payment_type_id', $paymentType->id)->get();

        $currencyData = [];
        foreach ($currencies as $currency) {
            if ($currency->slug == 'USDT' || $currency->slug == 'BUSD') {
                $rateData = 1;
            } else {
                $rateData = $service->getCurrencyRate($currency);
                $currencyData[$currency->slug] = $rateData;
            }
            if ($rateData) {
                $currency->rate = $rateData;
                $currency->save();
            }
        }
        Log::info('UPDATE CURRENCIES', [
            'currencies' => $currencyData,
            'payment_type' => $paymentType->slug
        ]);
    }


    public function callback(Request $request, Order $order, PaymentCurrency $currency)
    {
        $paymentType = PaymentType::find($currency->payment_type_id);
        $service = $this->makeType($paymentType);
        return $service->callback($request, $order);
    }


    public function updateBalances(PaymentType $paymentType): void
    {
        $service = $this->makeType($paymentType);
        $service->updateBalances();
    }

    private function makeType(PaymentType $paymentType)
    {
        $className = __NAMESPACE__ . '\\Types\\' . Str::ucfirst(Str::camel($paymentType->slug));
        return new $className($paymentType->public_key, $paymentType->private_key, $paymentType->advanced_balance, $paymentType);
    }

}
