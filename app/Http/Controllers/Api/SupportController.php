<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use App\Models\SupportTicket;

class SupportController extends Controller
{
    /**
     * Obtener los tickets (hilos de conversación).
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);
        $isSuperAdmin = is_null($user->tenant_id);

        $query = SupportTicket::with(['user:id,name']);

        if ($isSuperAdmin) {
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->orderBy('last_message_at', 'desc')->get());
    }

    /**
     * Obtener mensajes de un ticket específico.
     */
    public function show($id)
    {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);
        $isSuperAdmin = is_null($user->tenant_id);

        $ticket = SupportTicket::findOrFail($id);
        
        // Seguridad: Solo el dueño o SuperAdmin ven el ticket
        if (!$isSuperAdmin && $ticket->user_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $messages = $ticket->messages()->with('sender:id,name')->orderBy('created_at', 'asc')->get();
        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages
        ]);
    }

    /**
     * Enviar un mensaje a un ticket existente o crear uno nuevo.
     */
    public function sendContact(Request $request)
    {
        $request->validate([
            'body' => 'required|string',
            'subject' => 'nullable|string|max:100',
            'ticket_id' => 'nullable|exists:support_tickets,id',
            'user_id' => 'nullable|exists:users,id' // Solo Admin para respuestas
        ]);

        $sender = auth('api')->user();
        if (!$sender) return response()->json(['error' => 'No autenticado'], 401);
        $isSuperAdmin = is_null($sender->tenant_id);

        $ticketId = $request->ticket_id;

        // Si no hay ticket_id, creamos uno nuevo (solo usuarios finales)
        if (!$ticketId) {
            if ($isSuperAdmin) {
                return response()->json(['error' => 'El administrador no puede iniciar hilos sin un ticket previo'], 400);
            }

            $ticket = SupportTicket::create([
                'user_id' => $sender->id,
                'tenant_id' => $sender->tenant_id,
                'subject' => $request->subject ?? 'Nueva Consulta',
                'status' => 'open',
                'last_message_at' => now()
            ]);
            $ticketId = $ticket->id;
        } else {
            $ticket = SupportTicket::findOrFail($ticketId);
            
            if ($ticket->status === 'closed') {
                return response()->json(['error' => 'Este ticket está cerrado y no admite más mensajes'], 403);
            }

            $ticket->update(['last_message_at' => now()]);
        }

        $message = SupportMessage::create([
            'ticket_id' => $ticketId,
            'user_id' => $ticket->user_id,
            'sender_id' => $sender->id,
            'tenant_id' => $sender->tenant_id,
            'body' => $request->body,
            'is_read' => false
        ]);

        if (!$isSuperAdmin) {
            $this->notifyTelegram($message, $sender);
        }

        return response()->json($message->load('sender:id,name'));
    }

    /**
     * Cerrar un ticket.
     */
    public function closeTicket($id)
    {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);
        
        $ticket = SupportTicket::findOrFail($id);
        
        // Por ahora permitimos que ambos (Usuario y Admin) cierren el ticket
        $ticket->update(['status' => 'closed']);

        return response()->json(['message' => 'Ticket cerrado exitosamente', 'ticket' => $ticket]);
    }

    /**
     * Marcar mensajes de un ticket como leídos.
     */
    public function markAsRead(Request $request)
    {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'No autenticado'], 401);
        
        $request->validate(['ticket_id' => 'required|exists:support_tickets,id']);

        SupportMessage::where('ticket_id', $request->ticket_id)
            ->where('is_read', false)
            ->where('sender_id', '!=', $user->id)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'Actualizado']);
    }

    /**
     * Obtener hilos pendientes (solo Super Admin).
     */
    public function pendingThreads()
    {
        $user = auth('api')->user();
        if (!$user || !is_null($user->tenant_id)) return response()->json([]);

        $threads = SupportTicket::where('status', 'open')
            ->whereHas('messages', function($q) use ($user) {
                $q->where('is_read', false)->where('sender_id', '!=', $user->id);
            })
            ->with(['user', 'messages' => function($q) {
                $q->orderBy('created_at', 'desc')->limit(1);
            }])
            ->get();

        return response()->json($threads);
    }

    public function pendingCount()
    {
        $user = auth('api')->user();
        if (!$user || !is_null($user->tenant_id)) return response()->json(['count' => 0]);

        $count = SupportMessage::where('is_read', false)
            ->where('sender_id', '!=', $user->id)
            ->whereHas('ticket', function($q) {
                $q->where('status', 'open');
            })
            ->count();

        return response()->json(['count' => $count]);
    }

    private function notifyTelegram($message, $sender)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID'); // Cambiado a variable general según .env previo

        if (!$token || !$chatId) return;

        $tenantName = $sender->tenant ? $sender->tenant->name : 'N/A';
        $text = "🎫 *TICKET #{$message->ticket_id}*\n";
        $text .= "--------------------------------\n";
        $text .= "👤 *Usuario:* {$sender->name}\n";
        $text .= "🏢 *Empresa:* {$tenantName}\n";
        $text .= "📝 *Mensaje:* {$message->body}\n";
        $text .= "--------------------------------";

        try {
            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]);
        } catch (\Exception $e) {
            Log::error("Error Telegram: " . $e->getMessage());
        }
    }
}