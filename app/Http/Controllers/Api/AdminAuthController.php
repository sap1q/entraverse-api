<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        if (! Auth::guard('admin')->attempt($credentials)) {
            return response()->json([
                'message' => 'Kredensial tidak valid',
            ], 401);
        }

        $request->session()->regenerate();
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin) {
            Auth::guard('admin')->logout();
            return response()->json([
                'message' => 'Kredensial tidak valid',
            ], 401);
        }

        $admin->forceFill([
            'last_login_at' => now(),
        ])->save();

        $token = $admin->createToken('admin-panel')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'admin' => $admin,
        ]);
    }
}
