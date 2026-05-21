<?php

namespace Rhino\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Rhino\Jobs\SendPasswordRecoveryEmailJob;
use Rhino\Models\OrganizationInvitation;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('API Token')->plainTextToken;

        // Get the first organization the user belongs to
        $firstOrganization = $user->organizations()->first();
        $organizationSlug = $firstOrganization ? $firstOrganization->slug : null;

        return response()->json([
            'token' => $token,
            'organization_slug' => $organizationSlug,
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully'], 200);
    }

    public function recoverPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT ?
            response()->json(['message' => 'Password recovery email sent.'], 200) :
            response()->json(['message' => 'Unable to send password recovery email.'], 500);
    }

    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset.'], 200)
            : response()->json(['message' => 'Token is invalid or expired.'], 400);
    }

    /**
     * Register a new user with an invitation token.
     */
    public function registerWithInvitation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|size:64',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $token = $request->input('token');
        
        $invitation = OrganizationInvitation::where('token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invalid or expired invitation token'
            ], 404);
        }

        if ($invitation->isExpired()) {
            $invitation->status = 'expired';
            $invitation->save();
            
            return response()->json([
                'message' => 'This invitation has expired'
            ], 422);
        }

        // Validate email matches invitation
        if ($invitation->email !== $request->input('email')) {
            return response()->json([
                'message' => 'Email does not match the invitation'
            ], 422);
        }

        // Create user
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        // Accept invitation (adds user to organization)
        $invitation->accept($user);

        // Generate token
        $token = $user->createToken('API Token')->plainTextToken;

        // Get organization slug for redirect
        $organization = $invitation->organization;
        $organizationSlug = $organization ? $organization->slug : null;

        return response()->json([
            'message' => 'Registration successful',
            'token' => $token,
            'user' => $user,
            'organization_slug' => $organizationSlug,
        ], 201);
    }
}
