<?php

namespace App\Http\Controllers;

use App\Services\Cabme\AuthApi;
use App\Support\Rider;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly AuthApi $api,
        private readonly Rider $rider,
    ) {
    }

    public function edit()
    {
        return view('app.profile');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'max:8'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $files = $request->hasFile('photo') ? ['photo' => $request->file('photo')] : [];

        $result = $this->api->updateProfile([
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'country_code' => $data['country_code'],
        ], $files);

        if ($result->failed()) {
            return back()->withInput()
                ->withErrors(['profile' => $result->messageOr(__('Could not update your profile.'))]);
        }

        // Keep the session snapshot in step with what the admin now holds.
        $this->rider->updateProfile(is_array($result->data) ? $result->data : [
            'prenom' => $data['firstname'],
            'nom' => $data['lastname'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'country_code' => $data['country_code'],
        ]);

        return back()->with('status', __('Profile updated.'));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $result = $this->api->changePassword($data['current_password'], $data['password']);

        return $result->ok
            ? back()->with('status', __('Password changed.'))
            : back()->withErrors(['password' => $result->messageOr(__('Could not change your password.'))]);
    }

    public function referrals()
    {
        return view('app.referrals', ['referral' => $this->api->referralCode()->data]);
    }

    public function destroy(Request $request)
    {
        $result = $this->api->deleteAccount();

        if ($result->failed()) {
            return back()->withErrors(['delete' => $result->messageOr(__('Could not delete your account.'))]);
        }

        $this->rider->logout();

        return redirect()->route('home')->with('status', __('Your account has been deleted.'));
    }
}
