<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ParcelController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\RideController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TopupController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CabMe Web — rider panel
|--------------------------------------------------------------------------
| This panel owns no database. Controllers are thin wrappers over the
| cabme-admin REST API (see App\Services\Cabme).
*/

/* ------------------------------------------------------------------ public */

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/services', [PageController::class, 'services'])->name('services');
Route::get('/coverage', [PageController::class, 'coverage'])->name('coverage');
Route::get('/faq', [ContentController::class, 'faq'])->name('faq');
Route::get('/privacy', [ContentController::class, 'privacy'])->name('privacy');
Route::get('/terms', [ContentController::class, 'terms'])->name('terms');
Route::get('/contact', [ContentController::class, 'contact'])->name('contact');
/* Any other page the admin publishes under CMS Pages. */
Route::get('/page/{slug}', [ContentController::class, 'page'])->name('page');
Route::post('/locale', [PageController::class, 'setLocale'])->name('locale.switch');

/* -------------------------------------------------------------------- auth */

Route::middleware('guest.rider')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');

    // Firebase-verified sign-in (phone OTP / Google) — same admin endpoints
    // the mobile apps use.
    Route::post('/auth/firebase', [\App\Http\Controllers\SocialAuthController::class, 'login'])
        ->middleware('throttle:15,1')->name('auth.firebase');
    Route::post('/auth/firebase/register', [\App\Http\Controllers\SocialAuthController::class, 'register'])
        ->middleware('throttle:10,1')->name('auth.firebase.register');

    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.forgot');
    Route::post('/forgot-password', [AuthController::class, 'sendOtp'])->middleware('throttle:5,1');
    Route::get('/reset-password', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/* ------------------------------------------------------------- rider area */

Route::middleware('rider')->prefix('app')->name('app.')->group(function () {

    Route::get('/', [PageController::class, 'dashboard'])->name('home');

    /* ride */
    Route::get('/ride', [RideController::class, 'create'])->name('ride.create');
    Route::post('/ride/quote', [RideController::class, 'quote'])->name('ride.quote');
    Route::post('/ride', [RideController::class, 'store'])->name('ride.store');
    Route::get('/ride/active', [RideController::class, 'active'])->name('ride.active');
    Route::get('/ride/status', [RideController::class, 'status'])->name('ride.status');
    Route::post('/ride/{ride}/cancel', [RideController::class, 'cancel'])->name('ride.cancel');
    Route::post('/ride/{ride}/increase-fare', [RideController::class, 'increaseFare'])->name('ride.increase-fare');
    /* Pay Now (on ride): gateway charge for the server-computed total, then
       complete — paying is what finishes the ride, as in the app. */
    Route::post('/ride/{ride}/pay/order', [RideController::class, 'payOrder'])->whereNumber('ride')->name('ride.pay.order');
    Route::post('/ride/{ride}/complete', [RideController::class, 'complete'])->whereNumber('ride')->name('ride.complete');

    /* parcel */
    Route::get('/parcel', [ParcelController::class, 'create'])->name('parcel.create');
    Route::post('/parcel/quote', [ParcelController::class, 'quote'])->name('parcel.quote');
    Route::post('/parcel', [ParcelController::class, 'store'])->name('parcel.store');
    Route::post('/parcel/{parcel}/cancel', [ParcelController::class, 'cancel'])->name('parcel.cancel');
    Route::get('/parcel/{parcel}/track', [ParcelController::class, 'track'])->name('parcel.track');
    Route::get('/parcel/{parcel}/status', [ParcelController::class, 'status'])->name('parcel.status');

    /* rental */
    Route::get('/rental', [RentalController::class, 'create'])->name('rental.create');
    Route::post('/rental', [RentalController::class, 'store'])->name('rental.store');
    Route::post('/rental/{rental}/cancel', [RentalController::class, 'cancel'])->name('rental.cancel');
    Route::get('/rental/{rental}/track', [RentalController::class, 'track'])->whereNumber('rental')->name('rental.track');
    Route::get('/rental/{rental}/status', [RentalController::class, 'status'])->whereNumber('rental')->name('rental.status');
    /* Pay Now (after the driver files the final km): the rental's cost is
       only final then, so nothing is charged at booking. */
    Route::post('/rental/{rental}/pay/order', [RentalController::class, 'payOrder'])->whereNumber('rental')->name('rental.pay.order');
    Route::post('/rental/{rental}/complete', [RentalController::class, 'complete'])->whereNumber('rental')->name('rental.complete');
    Route::post('/rental/{rental}/payment-method', [RentalController::class, 'changePayment'])->whereNumber('rental')->name('rental.payment-method');

    /* history */
    Route::get('/trips/{type?}', [TripController::class, 'index'])
        ->whereIn('type', ['ride', 'parcel', 'rental'])->name('trips');
    Route::get('/trips/{type}/{id}', [TripController::class, 'show'])
        ->whereIn('type', ['ride', 'parcel', 'rental'])->whereNumber('id')->name('trips.show');
    Route::post('/trips/{type}/{id}/review', [TripController::class, 'review'])
        ->whereIn('type', ['ride', 'parcel', 'rental'])->whereNumber('id')->name('trips.review');
    // A complaint belongs to a booking, so it is raised from the trip it is
    // about rather than from a free-form support form.
    Route::post('/trips/{type}/{id}/complaint', [SupportController::class, 'store'])
        ->whereIn('type', ['ride', 'parcel', 'rental'])->whereNumber('id')->name('trips.complaint');

    /* wallet */
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
    // Razorpay: the server sets the amount, verifies with Razorpay, then credits.
    Route::post('/wallet/topup/razorpay/order', [TopupController::class, 'createOrder'])->name('wallet.razorpay.order');
    Route::post('/wallet/topup/razorpay/verify', [TopupController::class, 'verify'])->name('wallet.razorpay.verify');

    /* profile */
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'changePassword'])->name('profile.password');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/referrals', [ProfileController::class, 'referrals'])->name('referrals');

    /* booking gateway charges (parcel, rental): server-set amount, verified */
    Route::post('/pay/order', [PayController::class, 'createOrder'])->name('pay.order');
    Route::post('/pay/verify', [PayController::class, 'verify'])->name('pay.verify');
    // Hosted-page gateways land their popup here; it relays the query to the
    // opener tab, where the server-side verification actually runs.
    Route::get('/pay/return', fn () => view('pay.return'))->name('pay.return');

    /* rider ↔ driver chat: the thread itself is Firestore, this only
       pushes the driver a notification they would otherwise miss. */
    Route::post('/chat/notify', [\App\Http\Controllers\ChatController::class, 'notify'])
        ->middleware('throttle:60,1')->name('chat.notify');

    /* change payment method on an active ride */
    Route::post('/ride/{ride}/payment-method', [RideController::class, 'changePayment'])->name('ride.payment-method');

    /* web push */
    Route::post('/push/subscribe', [\App\Http\Controllers\PushController::class, 'subscribe'])->name('push.subscribe');

    /* support */
    Route::get('/support', [SupportController::class, 'index'])->name('support');
    Route::post('/sos', [SupportController::class, 'sos'])->name('sos');
});

/* Guests compose a trip on the landing page, then sign in to price and book it. */
Route::post('/start', [PageController::class, 'start'])->name('home.start');

/* Server-to-server payment notifications (no session, CSRF-exempt). */
Route::post('/webhooks/payfast', [\App\Http\Controllers\PayfastWebhookController::class, 'handle'])->name('webhooks.payfast');

/*
| Local-only session seeder for the browser smoke test. Registered only outside
| production so it can never become a way to forge a session on a live site.
*/
if (! app()->environment('production')) {
    Route::get('/__test-login', function (\Illuminate\Http\Request $request, \App\Support\Rider $rider) {
        $rider->login([
            'id' => (int) $request->query('id'),
            'accesstoken' => (string) $request->query('token'),
            'prenom' => 'Test',
            'nom' => 'Rider',
            'email' => (string) $request->query('email', ''),
            'phone' => (string) $request->query('phone', ''),
        ]);

        return redirect('/app');
    });
}
