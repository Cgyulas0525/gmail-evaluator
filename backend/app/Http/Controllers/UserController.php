<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'email_verified_at', 'created_at']);

        return response()->json($users);
    }

    public function verify(User $user): JsonResponse
    {
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'A felhasználó már meg van erősítve.',
                'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'created_at']),
            ]);
        }

        $user->email_verified_at = now();
        $user->save();

        return response()->json([
            'message' => 'A felhasználó sikeresen meg erősítve.',
            'user' => $user->fresh(['id', 'name', 'email', 'email_verified_at', 'created_at']),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'Ez az e-mail cím már foglalt.',
            'password.min' => 'A jelszó legalább 8 karakter legyen.',
            'password.confirmed' => 'A jelszavak nem egyeznek.',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json([
            'message' => 'A felhasználó sikeresen módosítva.',
            'user' => $user->fresh(['id', 'name', 'email', 'email_verified_at', 'created_at']),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'A saját fiókodat nem törölheted.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'A felhasználó sikeresen törölve.',
        ]);
    }
}
