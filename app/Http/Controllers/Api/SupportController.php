<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SupportController extends Controller
{
    public function sendContact(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:100',
            'message' => 'required|string|min:10',
        ]);

        $user = Auth::user();
        $tenant = $user->tenant;

        // Por ahora lo guardamos en el log de Laravel (storage/logs/laravel.log)
        Log::info("SOPORTE DE USUARIO: {$user->name} (Empresa: {$tenant->name})");
        Log::info("Asunto: {$request->subject}");
        Log::info("Mensaje: {$request->message}");

        return response()->json([
            'message' => 'Tu solicitud de soporte ha sido enviada. Revisaremos tu caso pronto.'
        ]);
    }
}   