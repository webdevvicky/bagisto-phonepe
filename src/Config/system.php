<?php

return [
    [
        'key'    => 'sales.payment_methods.phonepe',
        'name'   => 'PhonePe',
        'info'   => 'PhonePe Payment Gateway',
        'sort'   => 4,
        'fields' => [
            [
                'name'          => 'title',
                'title'         => 'Title',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],
            [
                'name'          => 'description',
                'title'         => 'Description',
                'type'          => 'textarea',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],
            [
                'name'          => 'image',
                'title'         => 'Logo',
                'type'          => 'image',
                'channel_based' => false,
                'locale_based'  => false,
                'validation'    => 'mimes:bmp,jpeg,jpg,png,webp',
            ],

            // ⚠️ REQUIRED: Merchant ID is still used in Create Order/Status calls
            [
                'name'          => 'merchant_id',
                'title'         => 'Merchant ID',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],

            // 🔐 NEW: OAuth Credentials for /oauth/token
            [
                'name'          => 'client_id',
                'title'         => 'Client ID',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => false,
            ],
            [
                'name'          => 'client_secret',
                'title'         => 'Client Secret',
                'type'          => 'password',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => false,
            ],
            [
                'name'          => 'client_version',
                'title'         => 'Client Version',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => false,
            ],

            [
                'name'    => 'env',
                'title'   => 'Environment',
                'type'    => 'select',
                'validation' => 'required',
                'options' => [
                    ['title' => 'Sandbox',   'value' => 'sandbox'],
                    ['title' => 'Production','value' => 'production'],
                ],
            ],

            [
                'name'          => 'active',
                'title'         => 'Status',
                'type'          => 'boolean',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],
        ],
    ],
];
