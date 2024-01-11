<?php

namespace App\Services\Payments\Types;

use App\Models\Order;
use App\Models\PaymentCurrency;
use App\Models\PaymentType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\WithdrawWallet;
use Illuminate\Http\Request;

abstract class Base
{

    public function __construct(
        private string|null $publicKey,
        private string|null $privateKey,
        private string|null $advancedBalance,
        private PaymentType $paymentType
    )
    {
    }


    public abstract function createInvoice(PaymentCurrency $currency, Order $order);


    public abstract function callback(Request $request, Order &$order);

    public function getCurrencyRate(PaymentCurrency $currency): float
    {
        return 1;
    }

    public function withdrawToWallets(): array
    {
        return [];
    }

    public function withdrawOrderToWallet(Order $order): bool
    {
        return true;
    }

    public function withdrawForUser(Withdraw $withdraw, string $wallet, WithdrawWallet $withdrawWallet): false|string
    {
        return false;
    }

    public function updateBalances(): void
    {
    }


    public function checkWithdraw(Withdraw $withdraw): bool
    {
        return true;
    }

}
