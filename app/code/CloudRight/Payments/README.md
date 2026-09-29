# CloudRight_Payments

A native Magento 2 extension that adds a **CloudRight Secure Payment** checkout modal on top of the Magento cart page, and a matching `cloudright` Magento payment method.

- **Vendor:** CloudRight
- **Module:** Payments
- **Target platform:** Magento Open Source 2.4.8-p5, PHP 8.4
- **Location:** `app/code/CloudRight/Payments/`

This module does **not** modify any Magento core files and does **not** replace Magento's own checkout. It intercepts the "Proceed to Checkout" buttons on the cart page and opens its own modal instead, then creates a real Magento order using Magento's own quote/order service contracts.

## What's included

| Type | Purpose |
|---|---|
| `Api/OrderManagementInterface.php` + `Model/OrderManagement.php` | Service contract that turns the current quote into a real Magento order via `QuoteManagement::placeOrder()`. |
| `Api/PaymentInterface.php` + `Model/Payment.php` | Service contract for evaluating/recording a CloudRight transaction (test/dev implementation today). |
| `Model/PaymentMethod.php` | Registers `cloudright` as a selectable Magento payment method ("CloudRight Secure Payment"). |
| `Controller/Cart/Index.php` | `GET /cloudright/cart` — returns the current quote as JSON. |
| `Controller/Payment/Process.php` | `POST /cloudright/payment/process` — storefront endpoint the modal calls to place the order. |
| `Controller/Checkout/Index.php` | `GET /cloudright/checkout` — redirects to the cart page (the CloudRight UI lives on the cart page as a modal, not a separate page). |
| `etc/webapi.xml` | Exposes `POST /V1/cloudright/order` (same logic as the frontend endpoint, callable as a REST API). |
| `view/frontend/*` | The modal markup/CSS/JS injected only onto `checkout_cart_index` (the cart page). |
| `etc/db_schema.xml` + `etc/db_schema_whitelist.json` | Adds `cloudright_transaction_id` and `cloudright_payment_status` columns to `sales_order` via Magento declarative schema. |

## Installation

1. Copy the module into your Magento installation so that you end up with:

   ```
   <magento-root>/app/code/CloudRight/Payments/
   ```

2. From the Magento root, run:

   ```bash
   php bin/magento module:enable CloudRight_Payments
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento setup:static-content:deploy -f
   php bin/magento cache:flush
   ```

   (Skip `setup:static-content:deploy` if your environment runs in developer mode.)

3. If you are running Magento inside Docker, run the same commands inside the PHP container, e.g.:

   ```bash
   docker compose exec php-fpm php bin/magento setup:upgrade
   docker compose exec php-fpm php bin/magento setup:di:compile
   docker compose exec php-fpm php bin/magento setup:static-content:deploy -f
   docker compose exec php-fpm php bin/magento cache:flush
   ```

## Testing the checkout flow

Using a local Magento instance at `http://localhost:8080/`:

1. Add a product to the cart.
2. Open `http://localhost:8080/checkout/cart/`.
3. Click **Proceed to Checkout** (or the mini-cart's checkout button).
4. Magento's normal checkout does **not** open — the CloudRight modal opens instead.
5. Your cart items and total are shown.
6. Enter an email address and click **Continue**.
7. Review the order on the Payment step and click **Pay Securely**.
8. A test transaction id (`CLOUDRIGHT-DUMMY-{timestamp}`) is generated and sent to the module's order endpoint.
9. A real Magento order is created and its order number is shown on the Confirmation step.
10. Click **Continue Shopping** to close the modal and return to the (now empty) cart.

## API endpoints

### `GET /cloudright/cart`

Returns the current guest or logged-in cart:

```json
{
  "success": true,
  "items": [
    {
      "item_id": 1,
      "product_id": 12,
      "sku": "24-MB01",
      "name": "Joust Duffle Bag",
      "qty": 1,
      "price": {"value": 34.00, "formatted": "$34.00"},
      "row_total": {"value": 34.00, "formatted": "$34.00"},
      "image_url": "https://.../joust-bag.jpg"
    }
  ],
  "item_count": 1,
  "totals": {
    "subtotal": {"value": 34.00, "formatted": "$34.00"},
    "shipping": {"value": 5.00, "formatted": "$5.00"},
    "grand_total": {"value": 39.00, "formatted": "$39.00"}
  }
}
```

### `POST /cloudright/payment/process` and `POST /V1/cloudright/order`

Both accept the same payload and run the same `OrderManagementInterface::createOrder()` logic:

```json
{
  "email": "customer@example.com",
  "transactionId": "CLOUDRIGHT-DUMMY-123456789"
}
```

Response:

```json
{
  "success": true,
  "order_id": 1,
  "order_increment_id": "000000001",
  "transaction_id": "CLOUDRIGHT-DUMMY-123456789",
  "message": "CloudRight order created successfully."
}
```

The REST endpoint is registered with the `anonymous` ACL resource so it can also be called by guest shoppers.

## Database fields

Added to `sales_order` via `etc/db_schema.xml`:

- `cloudright_transaction_id` (varchar, nullable) — the CloudRight transaction id associated with the order.
- `cloudright_payment_status` (varchar, nullable) — `paid`, `pending`, or `failed`.

## Development / test payment limitation

This module intentionally does **not** connect to a live payment gateway. `Model/Payment.php` only validates the shape of a transaction id (currently the `CLOUDRIGHT-DUMMY-{timestamp}` id generated by the storefront JavaScript) and records a `paid`/`pending`/`failed` status — it does not perform a real authorization/capture, and the transaction id from the browser is never treated as verified proof of payment beyond this test context.

Address collection is also simplified for this first version: the modal only collects an email address, so `Model/OrderManagement.php` fills in a placeholder shipping/billing address (and picks the first available shipping rate) when the cart is not virtual, purely so a complete Magento order can be created end-to-end for testing. This is clearly isolated in `OrderManagement::prepareAddresses()`.

## Future real CloudRight API integration

The module is structured so a real integration can be added without touching the controllers, the webapi route, or the payment method registration:

- Replace the body of `Model/Payment::processPayment()` with a real HTTP call to the CloudRight payment API (authorize/capture), still returning one of `PaymentInterface::STATUS_PAID` / `STATUS_PENDING` / `STATUS_FAILED`.
- Extend `Model/PaymentMethod.php` (or switch it to `\Magento\Payment\Model\Method\Adapter` with a command pool) once real authorize/capture calls are available.
- Replace the placeholder logic in `OrderManagement::prepareAddresses()` with real address fields collected in an extra step of the checkout modal.
- Expand the simple email-based customer handling in `OrderManagement::createOrder()` with `Magento\Customer\Api\CustomerRepositoryInterface` / `AccountManagementInterface` to look up or create full customer accounts instead of guest checkout only.

No changes to `Api/*Interface.php`, `etc/webapi.xml`, `etc/di.xml`, or the frontend routes are required to make these upgrades.
