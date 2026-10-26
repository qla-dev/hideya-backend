<?php

/*
 * In-app purchases through RevenueCat, as in qla.fit: the app buys a package,
 * then the server asks RevenueCat's REST API what the account actually bought
 * and grants each store transaction once (revenuecat_purchases). Nothing the
 * app sends decides what is granted. Keep the identifiers in step with
 * frontend/src/lib/purchases.ts.
 *
 * RevenueCat: offering `powerups` holds the packs and offering `plus` the
 * subscription, each package named after its product's last segment
 * (`powerups15`, `plusmonthly`); entitlement `plus` is Hideya Plus.
 */
return [
    // RevenueCat project secret key (sk_…), read on the server only.
    'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
    // app_user_id the app logs in to RevenueCat with, before the account id.
    'app_user_prefix' => 'hideya-user-',

    // Consumable packs: product id => power-ups it adds (an even mix of the four kinds).
    'packs' => [
        'hideya.qla.dev.powerups5' => 5,
        'hideya.qla.dev.powerups15' => 15,
        'hideya.qla.dev.powerups40' => 40,
    ],

    // Hideya Plus, the monthly subscription, and the entitlement it unlocks.
    'plus_product' => 'hideya.qla.dev.plusmonthly',
    'plus_entitlement' => 'plus',
];
