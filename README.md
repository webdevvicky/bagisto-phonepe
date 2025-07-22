# PhonePe Payment Gateway Integration for Bagisto by webdevvicky

This package provides a seamless integration of PhonePe payment gateway with Bagisto applications.

**This integration uses PhonePe API Version 2**

## Installation

1. Install the package via Composer:

   ```php
   composer require webdevvicky/bagisto-phonepe
   ```

2. Register the Phonepe service provider in Bootstrap/providers.php:
 ```php
  Webdevvicky\Phonepe\Providers\PhonepeServiceProvider::class,
 ```

3. Navigate to your admin panel:
Go to Configure/Payment Methods
Phonepe will appear at the end of the payment method list

4. Add the Phonepe route to CSRF token verification exceptions in bootstrap/app.php withMiddleware(function (Middleware $middleware) :
 ```php
$middleware->validateCsrfTokens(except: [
    '/phonepe/callback',
]);
 ```

5. Clear your configuration cache:
```php
php artisan config:cache
```
