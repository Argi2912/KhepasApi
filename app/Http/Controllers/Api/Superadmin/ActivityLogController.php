<?php

namespace App\Http\Controllers\Api\Superadmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        // 1. Base Query con relaciones
        // 'causer' = Quién hizo la acción (User)
        // 'subject' = El modelo afectado (CurrencyExchange, Client, etc)
        $query = Activity::with(['causer', 'subject'])->latest();

        // 2. Filtros
        if ($request->filled('search')) {
            $query->where('description', 'like', "%{$request->search}%")
                  ->orWhere('log_name', 'like', "%{$request->search}%");
        }

        if ($request->filled('event')) { // created, updated, deleted
            $query->where('event', $request->event);
        }

        if ($request->filled('subject_type')) { // App\Models\Client, etc.
            $query->where('subject_type', 'like', "%{$request->subject_type}%");
        }

        if ($request->filled('causer_id')) { // ID de Usuario específico
            $query->where('causer_id', $request->causer_id);
        }

        // 3. Paginación
        $logs = $query->paginate(20);

        // 4. Transformación de datos para facilitar el Frontend
        $logs->getCollection()->transform(function ($activity) {
            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event, // created, updated...
                'subject_type' => class_basename($activity->subject_type), // "Client" en vez de "App\Models\Client"
                'subject_id' => $activity->subject_id,
                'causer_name' => $activity->causer ? $activity->causer->name : 'Sistema/Automático',
                'causer_email' => $activity->causer ? $activity->causer->email : null,
                // Intentamos obtener el Tenant del usuario que hizo la acción
                'tenant_name' => $activity->causer && $activity->causer->tenant ? $activity->causer->tenant->name : 'Global/N/A',
                'properties' => $activity->properties, // El JSON con los cambios (old vs attributes)
                'created_at' => $activity->created_at->format('Y-m-d H:i:s'),
                'time_ago' => $activity->created_at->diffForHumans(),
            ];
        });

        return response()->json($logs);
    }
}