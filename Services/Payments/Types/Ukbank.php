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
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Ukbank extends Base
{


    public function __construct(
        private string|null $publicKey,
        private string|null $privateKey,
        private string|null $advancedBalance,
        private PaymentType $paymentType
    )
    {
        parent::__construct($publicKey, $privateKey, $advancedBalance, $paymentType);
    }

    public function getCurrencyRate(PaymentCurrency $currency): float
    {
        $json = file_get_contents('https://api.privatbank.ua/p24api/pubinfo?exchange&coursid=5');
        $data = json_decode($json);
        foreach ($data as $currency) {
            if ($currency->ccy == 'USD') {
                return 1 / (($currency->buy + $currency->sale) / 2);
            }
        }
        return 37;
    }


    public function createInvoice(PaymentCurrency $currency, Order $order)
    {
        return false;
    }


    public function callback(Request $request, Order &$order)
    {
        return true;
    }
}
