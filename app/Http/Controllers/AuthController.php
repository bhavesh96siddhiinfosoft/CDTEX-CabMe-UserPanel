<?php

namespace App\Http\Controllers;

use App\Services\Cabme\AuthApi;
use App\Support\Rider;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthApi $api,
        private readonly Rider $rider,
    ) {
    }

    public function showLogin(Request $request)
    {
        // Stash where the rider was headed before the guard bounced them.
        if ($to = $request->session()->get('intended')) {
            $request->session()->keep('intended');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $result = $this->api->login($data['email'], $data['password']);

        if ($result->failed()) {
            // The admin returns "User not found" / "Incorrect Password." as
            // separate messages. Pass them through — they're rider-facing and
            // this account surface is already enumerable via registration.
            throw ValidationException::withMessages([
                'email' => $result->messageOr(__('Could not sign you in. Please try again.')),
            ]);
        }

        $profile = $result->data;

        if (! is_array($profile) || empty($profile['accesstoken'])) {
            throw ValidationException::withMessages([
                'email' => __('Sign-in succeeded but no session token was returned. Please contact support.'),
            ]);
        }

        $this->rider->login($profile);

        $intended = $request->session()->pull('intended');

        return redirect($intended ?: route('app.home'))
            ->with('status', 'Welcome back, '.$this->rider->name().'.');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'country_code' => ['required', 'string', 'max:8'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'referral_code' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $this->api->register($data);

        if ($result->failed()) {
            throw ValidationException::withMessages([
                'email' => $result->messageOr(__('Could not create your account. Please try again.')),
            ]);
        }

        // Registration does not return an access token, so sign in with the
        // credentials we just used rather than asking the rider to retype them.
        $login = $this->api->login($data['email'], $data['password']);

        if ($login->ok && is_array($login->data) && ! empty($login->data['accesstoken'])) {
            $this->rider->login($login->data);

            return redirect()->route('app.home')->with('status', __('Your account is ready.'));
        }

        // Admin can hold new accounts for approval (statut != 'yes'), in which
        // case login legitimately fails right after a successful signup.
        return redirect()->route('login')
            ->with('status', $login->messageOr(__('Account created. Please sign in.')));
    }

    public function logout(Request $request)
    {
        if ($this->rider->check()) {
            // Best-effort: clears the FCM id and the token row in the admin.
            $this->api->logout();
        }

        $this->rider->logout();

        return redirect()->route('home')->with('status', __('You have been signed out.'));
    }

    /* ---------------------------------------------------------- password */

    public function showForgot()
    {
        return view('auth.forgot-password');
    }

    public function sendOtp(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $result = $this->api->sendResetOtp($data['email']);

        if ($result->failed()) {
            throw ValidationException::withMessages([
                'email' => $result->messageOr(__('Could not send a reset code.')),
            ]);
        }

        return redirect()->route('password.reset', ['email' => $data['email']])
            ->with('status', __('We sent a reset code to your email.'));
    }

    public function showReset(Request $request)
    {
        return view('auth.reset-password', ['email' => $request->query('email')]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            // The admin issues a 4-digit code; catch a wrong length here
            // rather than bouncing off the API.
            'otp' => ['required', 'digits:4'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $result = $this->api->resetPassword([
            'email' => $data['email'],
            'otp' => $data['otp'],
            'password' => $data['password'],
        ]);

        if ($result->failed()) {
            throw ValidationException::withMessages([
                'otp' => $result->messageOr(__('Could not reset your password.')),
            ]);
        }

        return redirect()->route('login')->with('status', __('Password updated. Please sign in.'));
    }
}
