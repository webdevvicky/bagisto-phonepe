<?php

namespace Webdevvicky\Phonepe\Http\Controllers;

use Illuminate\Http\Request;
use Webkul\Checkout\Facades\Cart;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Transformers\OrderResource;
use Illuminate\Support\Facades\Http;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Checkout\Repositories\CustomerRepository;
use App\Http\Controllers\Controller;

class PhonepeController extends Controller
{
    protected $orderRepository;
    protected $invoiceRepository;

    public function __construct(OrderRepository $orderRepository, InvoiceRepository $invoiceRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
    }

    /**
     * Fetch PhonePe access token
     */
    protected function getAccessToken()
    {
        $clientId = core()->getConfigData('sales.payment_methods.phonepe.client_id');
        $clientVersion = core()->getConfigData('sales.payment_methods.phonepe.client_version');
        $clientSecret = core()->getConfigData('sales.payment_methods.phonepe.client_secret');
        $env = core()->getConfigData('sales.payment_methods.phonepe.env');

        if (empty($clientId) || empty($clientVersion) || empty($clientSecret)) {
            throw new \Exception('PhonePe client credentials are missing');
        }

        $url = $env === 'sandbox' ? 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token' : 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post($url, [
            'client_id' => $clientId,
            'client_version' => $clientVersion,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch access token: ' . $response->body());
        }

        $data = $response->json();
        if (!isset($data['access_token'])) {
            throw new \Exception('Access token not found in response');
        }

        return $data['access_token'];
    }

    /**
     * Initiate payment by creating an SDK order
     */
  public function redirect(Request $request)
    {
        try {
            $cart = Cart::getCart();
            if (!$cart) throw new \Exception('Cart not found');

            $amount = intval((($cart->sub_total + $cart->tax_total + ($cart->selected_shipping_rate->price ?? 0)) - $cart->discount_amount) * 100);
            $merchantOrderId = 'order_' . $cart->id . '_' . time();

            $accessToken = $this->getAccessToken();
            $env = core()->getConfigData('sales.payment_methods.phonepe.env');
            $url = ($env === 'sandbox')
                ? 'https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/pay'
                : 'https://api.phonepe.com/apis/pg/checkout/v2/pay';

            $payload = [
                "merchantOrderId" => $merchantOrderId,
                "amount" => $amount,
                "expireAfter" => 1200,
                "metaInfo" => [
                    "udf1" => "", "udf2" => "", "udf3" => "", "udf4" => "", "udf5" => ""
                ],
                "paymentFlow" => [
                    "type" => "PG_CHECKOUT",
                    "message" => "Payment for order $merchantOrderId",
                    "merchantUrls" => [
                        "redirectUrl" => route('phonepe.verify', ['order_id' => $merchantOrderId])
                    ]
                ]
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => "O-Bearer $accessToken",
            ])->post($url, $payload);

            if (!$response->successful()) {
                throw new \Exception('PhonePe /pay failed: ' . $response->body());
            }

            $data = $response->json();
            $redirectUrl = data_get($data, 'redirectUrl') ?: data_get($data, 'redirectUrl', data_get($data, 'data.redirectInfo.url'));

            if (!$redirectUrl) {
                throw new \Exception('No redirectUrl in /pay response');
            }

            session(['phonepe_order_id' => $merchantOrderId]);
            return redirect()->away($redirectUrl);

        } catch (\Exception $e) {
            \Log::error('PhonePe Payment Error: ' . $e->getMessage());
            return redirect()->route('shop.checkout.cart.index')->with('error', $e->getMessage());
        }
    }

    public function verify(Request $request)
    {
        try {
            $merchantOrderId = $request->input('order_id') ?: session('phonepe_order_id');
            if (!$merchantOrderId) throw new \Exception('Missing order_id');

            $accessToken = $this->getAccessToken();
            $env = core()->getConfigData('sales.payment_methods.phonepe.env');
            $url = ($env === 'sandbox')
                ? "https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/order/$merchantOrderId/status?details=false"
                : "https://api.phonepe.com/apis/pg/checkout/v2/order/$merchantOrderId/status?details=false";

            $resp = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => "O-Bearer $accessToken",
            ])->get($url);

            if (!$resp->successful()) {
                throw new \Exception('Status API failed: ' . $resp->body());
            }

            $data = $resp->json();
            if (data_get($data, 'state') === 'COMPLETED') {
                $cart = Cart::getCart(); // similar login logic as before
                if (!$cart) throw new \Exception('Cart expired');

                $order = $this->orderRepository->create((new OrderResource($cart))->jsonSerialize());
                $this->orderRepository->update(['status' => 'processing'], $order->id);
                Cart::deActivateCart();
                return redirect()->route('shop.checkout.onepage.success')->with('order_id', $order->id);
            }

            throw new \Exception('Payment not completed: ' . data_get($data, 'state'));

        } catch (\Exception $e) {
            \Log::error('PhonePe Verify Error: ' . $e->getMessage());
            return redirect()->route('shop.checkout.cart.index')->with('error', 'Payment verification failed.');
        }
    }
    

    /**
     * Prepare invoice data for the order
     */
    protected function prepareInvoiceData($order)
    {
        $invoiceData = ["order_id" => $order->id];
        foreach ($order->items as $item) {
            $invoiceData['invoice']['items'][$item->id] = $item->qty_to_invoice;
        }
        return $invoiceData;
    }
}