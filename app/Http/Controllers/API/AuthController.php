<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    /**
     * TRACK B: GOOGLE OAUTH START (Browser redirect)
     * GET /api/v1/auth/google/start
     */
    public function startGoogleAuth(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'redirect_uri' => 'required|string|max:2048',
        ]);

        $redirectUri = $request->string('redirect_uri')->toString();

        if (!$this->isSupportedRedirectUri($redirectUri)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid redirect_uri. Include a valid URI scheme.'
            ], 422);
        }

        $statePayload = [
            'redirect_uri' => $redirectUri,
            'issued_at' => now()->timestamp,
        ];

        $state = Crypt::encryptString(json_encode($statePayload, JSON_THROW_ON_ERROR));

        $url = $this->googleDriver()
            ->stateless()
            ->with([
                'state' => $state,
                'prompt' => 'select_account',
                'access_type' => 'offline',
            ])
            ->redirect()
            ->getTargetUrl();

        return redirect()->away($url);
    }

    /**
     * TRACK B: GOOGLE OAUTH CALLBACK
     * GET /api/v1/auth/google/callback
     */
    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $fallbackRedirect = config('app.url');
        $redirectUri = $fallbackRedirect;

        try {
            $state = $request->query('state');
            if (!is_string($state) || $state === '') {
                return redirect()->away($this->appendQueryParams($fallbackRedirect, [
                    'oauth_error' => 'missing_state',
                ]));
            }

            $statePayload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
            $candidateRedirect = $statePayload['redirect_uri'] ?? null;

            if (!is_string($candidateRedirect) || !$this->isSupportedRedirectUri($candidateRedirect)) {
                return redirect()->away($this->appendQueryParams($fallbackRedirect, [
                    'oauth_error' => 'invalid_redirect',
                ]));
            }

            $redirectUri = $candidateRedirect;
            $googleUser = $this->googleDriver()->stateless()->user();
            $googleToken = $googleUser->token ?? null;

            if (!$googleUser || !$googleUser->getEmail() || !is_string($googleToken) || $googleToken === '') {
                return redirect()->away($this->appendQueryParams($redirectUri, [
                    'oauth_error' => 'google_identity_missing',
                ]));
            }

            return redirect()->away($this->appendQueryParams($redirectUri, [
                'google_token' => $googleToken,
            ]));
        } catch (\Throwable $e) {
            return redirect()->away($this->appendQueryParams($redirectUri, [
                'oauth_error' => 'google_oauth_failed',
            ]));
        }
    }

    /**
     * TRACK A: TRADITIONAL EMAIL REGISTRATION
     * POST /api/v1/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password), // Encrypts the password securely
        ]);

        // Issue standard mobile access token
        $token = $user->createToken('aura_mobile_device')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $user
        ], 201);
    }

    /**
     * TRACK A: TRADITIONAL EMAIL LOGIN
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        // Passwords hash verification check
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The provided credentials do not match our records.'
            ], 401);
        }

        $user->tokens()->delete(); // Clear old tokens
        $token = $user->createToken('aura_mobile_device')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $user
        ]);
    }

    /**
     * TRACK B: GOOGLE / GMAIL FEDERATED SIGN-IN
     * POST /api/v1/auth/google
     */
    public function handleGoogleSignIn(Request $request): JsonResponse
    {
        $request->validate([
            'google_token' => 'required|string',
        ]);

        try {
            // Direct handshake with Google using Socialite to extract verified user data
            $googleUser = $this->googleDriver()->userFromToken($request->input('google_token'));
            
            if (!$googleUser || !$googleUser->getEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired Google authentication credentials.'
                ], 422);
            }

            // Provision a user record dynamically in PostgreSQL if it's their first time logging in
            $user = User::firstOrCreate(
                ['email' => $googleUser->getEmail()],
                [
                    'name'              => $googleUser->getName() ?? 'Aura Scholar',
                    'password'          => Hash::make(Str::random(24)), // Random string since they log in via Google
                    'email_verified_at' => now(),
                ]
            );

            $user->tokens()->delete();
            $token = $user->createToken('aura_mobile_device')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Gmail identity confirmed.',
                'token'   => $token,
                'user'    => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Identity sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * SESSION CLOSE (LOGOUT)
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        // Delete the explicit token currently accessing the route
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Session securely closed.'
        ]);
    }

    private function isSupportedRedirectUri(string $redirectUri): bool
    {
        $parts = parse_url($redirectUri);

        return is_array($parts)
            && isset($parts['scheme'])
            && is_string($parts['scheme'])
            && $parts['scheme'] !== '';
    }

    private function appendQueryParams(string $url, array $params): string
    {
        return $url
            . (str_contains($url, '?') ? '&' : '?')
            . http_build_query($params);
    }

    private function googleDriver(): GoogleProvider
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver;
    }
}