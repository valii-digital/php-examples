<?php

namespace App\Services\Payments\Types;

use App\Facades\Balance;
use App\Models\Order;
use App\Models\PaymentCurrency;
use App\Models\PaymentType;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Local extends Base
{


    public function __construct(
        private string|null      $publicKey,
        private string|null      $privateKey,
        private string|null $advancedBalance,
        private PaymentType $paymentType
    )
    {
        parent::__construct($publicKey, $privateKey, $advancedBalance, $paymentType);
    }


    public function createInvoice(PaymentCurrency $currency, Order $order)
    {
        $user = User::find($order->user_id);
        if ($order->amount <= $user->balance) {
            Balance::addTransaction($order->amount * -1, Transaction::TYPE_BUY, $order->id, $user);
            $order->payment_success = true;
            $order->save();
            return [
                'finished' => true,
                'order_id' => $order->id
            ];
        }

        return false;
    }


    public function callback(Request $request, Order &$order)
    {
        return true;
    }
}
