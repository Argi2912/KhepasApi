<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    /**
     * Devuelve el log de auditoría PARA EL TENANT ACTUAL.
     */
    public function index(Request $request)
    {
        $tenantId = Auth::guard('api')->user()->tenant_id;

        // Si el usuario no tiene tenant (es Superadmin), no filtra por tenant.
        if (!$tenantId) {
             return Activity::with('causer:id,name', 'subject')
                ->latest()
                ->paginate(50);
        }

        // Filtramos el log por usuarios (causer) que pertenecen al tenant actual.
        $logs = Activity::with('causer:id,name', 'subject') // Carga 'causer' y 'subject'
            ->whereHas('causer', function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
            })
            ->latest()
            ->paginate(50);

        return $logs;
    }
}