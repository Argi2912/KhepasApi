<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupportController extends Controller
{
    /**
     * Obtener los mensajes del chat.
     * Si es Super Admin, puede ver todos o por usuario específico.
     * Si es Tenant User, solo ve los suyos.
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);
        $isSuperAdmin = is_null($user->tenant_id);

        $query = SupportMessage::with(['sender:id,name']);

        if ($isSuperAdmin) {
            // El Super Admin puede filtrar por un usuario específico (hilo)
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            } else {
                // O ver los últimos de todos (resumen)
                // Aquí podrías agrupar, pero para la campanita traemos los no leídos
                $query->where('is_read', false);
            }
        } else {
            // Los usuarios normales solo ven sus hilos
            $query->where('user_id', $user->id);
        }

        return response()->json($query->orderBy('created_at', 'asc')->get());
    }

    /**
     * Enviar un nuevo mensaje de soporte.
     */
    public function sendContact(Request $request)
    {
        $request->validate([
            'body' => 'required|string',
            'subject' => 'nullable|string|max:100',
            'user_id' => 'nullable|exists:users,id' // Solo lo usa el Admin para responder
        ]);

        $sender = auth('api')->user();
        if (!$sender) return response()->json(['error' => 'No autenticado'], 401);
        $isSuperAdmin = is_null($sender->tenant_id);

        // Si es admin, responde a un usuario. Si es usuario, inicia/sigue su hilo.
        $targetUserId = ($isSuperAdmin && $request->user_id) ? $request->user_id : $sender->id;

        $message = SupportMessage::create([
            'user_id' => $targetUserId,
            'sender_id' => $sender->id,
            'tenant_id' => $sender->tenant_id,
            'subject' => $request->subject,
            'body' => $request->body,
            'is_read' => false
        ]);

        // Si el mensaje viene de un CLIENTE (no admin), notificamos al Super Admin vía Telegram
        if (!$isSuperAdmin) {
            $this->notifyTelegram($message, $sender);
        }

        return response()->json($message->load('sender:id,name'));
    }

    /**
     * Marcar mensajes como leídos.
     */
    public function markAsRead(Request $request)
    {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);
        $isSuperAdmin = is_null($user->tenant_id);

        $query = SupportMessage::where('is_read', false);

        if ($isSuperAdmin) {
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id)->where('sender_id', '!=', $user->id);
            }
        } else {
            $query->where('user_id', $user->id)->where('sender_id', '!=', $user->id);
        }

        $query->update(['is_read' => true]);

        return response()->json(['message' => 'Actualizado']);
    }

    /**
     * Obtener lista de hilos (usuarios) con mensajes pendientes.
     * Solo para Super Admin.
     */
    public function pendingThreads()
    {
        $user = auth('api')->user();
        if (!$user || !is_null($user->tenant_id)) {
            return response()->json([]);
        }

        // Obtenemos los usuarios que tienen mensajes no leídos enviados por ellos
        $threads = SupportMessage::where('is_read', false)
            ->where('sender_id', '!=', $user->id)
            ->with('sender:id,name')
            ->select('user_id', \DB::raw('count(*) as count'), \DB::raw('max(created_at) as last_message_at'))
            ->groupBy('user_id')
            ->get();

        return response()->json($threads);
    }

    /**
     * Obtener conteo total de mensajes no leídos (badge).
     */
    public function pendingCount()
    {
        $user = auth('api')->user();
        if (!$user || !is_null($user->tenant_id)) return response()->json(['count' => 0]);

        $count = SupportMessage::where('is_read', false)
            ->where('sender_id', '!=', $user->id)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Enviar notificación a Telegram.
     */
    private function notifyTelegram($message, $sender)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_ADMIN_CHAT_ID');

        if (!$token || !$chatId) {
            Log::warning("Telegram no configurado. Token o ChatID faltantes.");
            return;
        }

        $tenantName = $sender->tenant ? $sender->tenant->name : 'N/A';
        $text = "🚀 *NUEVO MENSAJE DE SOPORTE*\n";
        $text .= "--------------------------------\n";
        $text .= "👤 *Usuario:* {$sender->name}\n";
        $text .= "🏢 *Empresa:* {$tenantName}\n";
        $text .= "📝 *Mensaje:* {$message->body}\n";
        $text .= "--------------------------------\n";
        $text .= "_Inicia sesión para responder_";

        try {
            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]);
        } catch (\Exception $e) {
            Log::error("Error enviando a Telegram: " . $e->getMessage());
        }
    }
}