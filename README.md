Eyelash Solutions 2.0 Template
===========================

A premium, editable beauty-services website built with HTML, CSS, JavaScript and PHP.

## Requirements

- PHP 8.1 or newer with PDO SQLite, fileinfo and cURL enabled
- Composer dependencies installed with `composer install`
- A PHP-capable host for checkout, staff/manager portals, settings and Stripe webhook routes. Static hosting alone does not run these features.

For local testing, copy `.env.example` to `.env`, then run `php -S 127.0.0.1:8080` from the project directory.

## Services and Checkout

Customers can add multiple services from the Services or Pricing pages and submit them together from `cart.html`. The server recalculates prices from `BOOKING_SERVICE_PRICES` in pence, validates that the selected room and assigned specialist support every service, and requires a phone number.

Managers choose `BOOKING_PAYMENT_MODE=manual` or `stripe` and set `BOOKING_DEPOSIT_PERCENT` (50 by default). These environment values are the initial defaults; settings saved through `manager.php` take precedence. Manual mode records the deposit due and lets the studio arrange collection. Stripe mode requires `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, and the public `BOOKING_SITE_URL`.

In Stripe, configure a webhook to `https://your-domain/stripe-webhook.php` for the `checkout.session.completed` event. Stripe Checkout requires its hosted session to remain open for at least 30 minutes; this app's booking hold expires after 15 minutes, and a payment arriving after the hold expires is refunded rather than confirming the booking.

The 15-minute hold starts when the checkout form is submitted. Customers can cancel it from the confirmation page or by leaving Stripe Checkout; staff can complete or cancel assigned requests from their portal. This template currently holds a submitted booking request, not a calendar time slot, because appointment dates and times are not collected.

Booking requests are stored in SQLite. Set `BOOKING_DATABASE_PATH` to a durable writable path outside the web root in production; if unset, the app uses a temporary system directory.

Studio rooms use `BOOKING_ROOMS`; each room lists service IDs it supports. Mobile coverage is configured per entry in `BOOKING_MOBILE_STAFF`:

- `servedPostcodes` is a list of exact UK postcodes.
- `serviceAreas` is a list of outward-code prefixes such as `SW1` or `EC1`.
- If neither list is configured, the specialist's radius is used, with a 5-mile default and a 15-mile maximum.

## Staff and Manager Access

The staff login is at `/staff.php`; after sign-in each person lands on `/staff-appointments.php`. A staff account's `id` must match an ID in `BOOKING_MOBILE_STAFF`; staff can only see bookings assigned to their own ID. Configure `BOOKING_STAFF_ACCOUNTS` as JSON in `.env` with each person's ID, display name, email and password hash.

The manager signs in at `/manager.php` and lands on `/admin.php`. The admin dashboard links to `/appointments.php`, which provides global search, status/specialist filters, and actions to complete, cancel, reassign, or edit active appointments. Editing preserves service lines and prices, and the server verifies the new room and specialist support every service. Set `BOOKING_MANAGER_EMAIL` and `BOOKING_MANAGER_PASSWORD_HASH` in `.env` before signing in. To generate a password hash locally, run `php -r '$password = readline("New password: "); echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;'` and put the resulting hash in `.env`; do not store a plain-text password there.

Managers can upload or replace a PNG, JPEG or WebP logo (up to 3 MB), set the page background color, and choose manual/Stripe payment with a deposit percentage. Logo and visual settings are stored in the private SQLite database. The sample DD Network footer logo is at `images/DD_network-logo.png`.

## Email and Customization

Set SMTP values in `.env` for real booking notifications. Without SMTP, the app falls back to PHP `mail()` and logs delivery failures to `contact.log`.

Edit brand copy and service labels in `template-config.js`; edit corresponding server prices in `BOOKING_SERVICE_PRICES` in `.env` so the displayed catalog and checkout remain aligned. Update the fictional contact details and sample staff/area settings before deploying a resale copy.
