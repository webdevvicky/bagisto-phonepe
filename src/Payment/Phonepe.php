<?php

namespace Vfixtechnology\Phonepe\Payment;

use Webkul\Payment\Payment\Payment;
use Illuminate\Support\Facades\Storage;

class Phonepe extends Payment
{
    /**
     * Payment method code
     *
     * @var string
     */
    protected $code  = 'phonepe';

    public function getRedirectUrl()
    {
        return route('phonepe.redirect');
    }

    public function isAvailable()
    {
        if (!$this->cart) {
            $this->setCart();
        }

        return $this->getConfigData('active') && $this->cart?->haveStockableItems();
    }

    public function getImage()
    {
        $url = $this->getConfigData('image');

        return $url ? Storage::url($url) : '';

    }
}
