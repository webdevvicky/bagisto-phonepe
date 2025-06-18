<?php

namespace Vfixtechnology\Phonepe\Http\Controllers;

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

   
    public function redirect(Request $request)
    {
        try {

            $cart = Cart::getCart();
           // \Log::info('Cart at redirect:', [Cart::getCart()]);

            if (!$cart) {
                throw new \Exception('Cart not found');
            }

            $billingAddress = $cart->billing_address;

            if (!$billingAddress || !$billingAddress->phone) {
                throw new \Exception('Billing address or phone number missing');
            }

            $shipping = $cart->selected_shipping_rate ? $cart->selected_shipping_rate->price : 0;
            $discount = $cart->discount_amount;
            $amount = ($cart->sub_total + $cart->tax_total + $shipping) - $discount;

            $orderId = 'order_' . $cart->id . '_' . time();

            $merchantId = core()->getConfigData('sales.payment_methods.phonepe.merchant_id');
            $saltKey = core()->getConfigData('sales.payment_methods.phonepe.salt_key');
            $saltIndex = core()->getConfigData('sales.payment_methods.phonepe.salt_index');
            $env = core()->getConfigData('sales.payment_methods.phonepe.env'); // 'sandbox' or 'production'

            // Validate required configuration
            if (empty($merchantId) || empty($saltKey) || empty($saltIndex)) {
                throw new \Exception('PhonePe payment configuration is incomplete');
            }

            $callbackUrl = route('phonepe.verify') . '?order_id=' . $orderId;

            $payload = [
                "merchantId" => $merchantId,
                "merchantTransactionId" => $orderId,
                "merchantUserId" => auth()->id() ?? 'guest_' . $billingAddress->phone,
                "amount" => intval($amount * 100), // in paise
                "redirectUrl" => $callbackUrl,
                "redirectMode" => "POST",
                "callbackUrl" => $callbackUrl,
                "mobileNumber" => $billingAddress->phone,
                "paymentInstrument" => [
                    "type" => "PAY_PAGE"
                ]
            ];

            $base64Payload = base64_encode(json_encode($payload));
            $checksum = hash('sha256', $base64Payload . "/pg/v1/pay" . $saltKey) . "###" . $saltIndex;

            $url = $env === 'sandbox'
                ? "https://api-preprod.phonepe.com/apis/pg-sandbox/pg/v1/pay"
                : "https://api.phonepe.com/apis/hermes/pg/v1/pay";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VERIFY' => $checksum,
                'Accept' => 'application/json',
            ])->post($url, [
                "request" => $base64Payload
            ]);

            if (!$response->successful()) {
                throw new \Exception('PhonePe API request failed: ' . $response->status());
            }

            $responseData = $response->json();

            // Log the response for debugging
            \Log::info('PhonePe Payment Init Response:', $responseData);

            if (isset($responseData['success']) && $responseData['success'] === true &&
                isset($responseData['data']['instrumentResponse']['redirectInfo']['url'])) {
                $request->session()->put('phonepe_order_id', $orderId);
                return redirect()->to($responseData['data']['instrumentResponse']['redirectInfo']['url']);
            }

            $errorMessage = $responseData['message'] ?? 'Unable to initiate payment';
            throw new \Exception($errorMessage);

        } catch (\Exception $e) {
            \Log::error('PhonePe Payment Error: ' . $e->getMessage());
            session()->flash('error', $e->getMessage());
            return redirect()->route('shop.checkout.cart.index');
        }
    }

    public function verify(Request $request)
    {
        try {
            \Log::info('PhonePe Verify Incoming Request:', $request->all());

            $orderId = $request->input('order_id') ?? $request->get('order_id');

            if (!$orderId) {
                session()->flash('error', 'PhonePe payment verification failed: Missing order ID');
                return redirect()->route('shop.checkout.cart.index');
            }

            // Get current cart or try to restore from order_id
            $cart = \Webkul\Checkout\Facades\Cart::getCart();

            if (!$cart) {
                preg_match('/order_(\d+)_/', $orderId, $matches);
                $cartId = $matches[1] ?? null;

                if ($cartId) {
                    $cart = app(\Webkul\Checkout\Repositories\CartRepository::class)->find($cartId);

                    if ($cart && $cart->customer_id) {
                        $customer = app(\Webkul\Customer\Repositories\CustomerRepository::class)->find($cart->customer_id);
                        if ($customer) {
                            auth('customer')->login($customer);
                            \Log::info('Customer logged in from cart ID: ' . $cartId);
                        }

                        \Webkul\Checkout\Facades\Cart::setCart($cart);
                        $cart = \Webkul\Checkout\Facades\Cart::getCart();
                    }
                }
            }

            if (!$cart) {
                session()->flash('error', 'Cart not found. Please try again.');
                return redirect()->route('shop.checkout.cart.index');
            }

            // Config values from admin
            $merchantId = core()->getConfigData('sales.payment_methods.phonepe.merchant_id');
            $saltKey    = core()->getConfigData('sales.payment_methods.phonepe.salt_key');
            $saltIndex  = core()->getConfigData('sales.payment_methods.phonepe.salt_index');
            $env        = core()->getConfigData('sales.payment_methods.phonepe.env');

            // Prepare status URL & checksum
            $path = "/pg/v1/status/{$merchantId}/{$orderId}";
            $baseUrl = $env === 'sandbox'
                ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
                : 'https://api.phonepe.com/apis/hermes';

            $statusUrl = $baseUrl . $path;
            $checksum = hash('sha256', $path . $saltKey) . "###" . $saltIndex;

            \Log::info('PhonePe Status URL: ' . $statusUrl);
            \Log::info('PhonePe Checksum: ' . $checksum);

            // Make status API call with both required headers
            $response = Http::withHeaders([
                'Content-Type'   => 'application/json',
                'X-VERIFY'       => $checksum,
                'X-MERCHANT-ID'  => $merchantId,
                'Accept'         => 'application/json',
            ])->get($statusUrl);

            \Log::info('PhonePe Status API Raw Response: ' . $response->body());

            if (!$response->successful()) {
                session()->flash('error', 'PhonePe verification failed. Try again later.');
                return redirect()->route('shop.checkout.cart.index');
            }

            $data = $response->json();
            \Log::info('PhonePe Status API Parsed Response:', $data);

            if (
                isset($data['success']) && $data['success'] === true &&
                isset($data['code']) && $data['code'] === 'PAYMENT_SUCCESS' &&
                isset($data['data']['state']) && in_array($data['data']['state'], ['SUCCESS', 'COMPLETED']) &&
                isset($data['data']['merchantTransactionId']) && $data['data']['merchantTransactionId'] === $orderId
            ) {
                $order = $this->orderRepository->create((new OrderResource($cart))->jsonSerialize());
                $this->orderRepository->update(['status' => 'processing'], $order->id);

                if ($order->canInvoice()) {
                    $this->invoiceRepository->create($this->prepareInvoiceData($order));
                }

                \Webkul\Checkout\Facades\Cart::deActivateCart();

                session()->flash('order_id', $order->id);
                return redirect()->route('shop.checkout.onepage.success');
            }

            session()->flash('error', 'PhonePe payment failed or was cancelled.');
            return redirect()->route('shop.checkout.cart.index');

        } catch (\Exception $e) {
            \Log::error('PhonePe Verify Exception: ' . $e->getMessage());
            session()->flash('error', 'Something went wrong during payment verification.');
            return redirect()->route('shop.checkout.cart.index');
        }
    }



    protected function prepareInvoiceData($order)
    {
        $invoiceData = ["order_id" => $order->id];

        foreach ($order->items as $item) {
            $invoiceData['invoice']['items'][$item->id] = $item->qty_to_invoice;
        }

        return $invoiceData;
    }
}
