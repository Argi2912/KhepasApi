<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Broker;
use Illuminate\Http\Request;

class BrokerController extends Controller
{
    public function index(Request $request)
    {
        // 1. Validar filtros (Ya no filtramos por user_id)
        $request->validate([
            'search' => 'nullable|string',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 2. Iniciar consulta (Ya no cargamos 'user')
        $query = Broker::query();

        // 3. Búsqueda por nombre o email
        $query->when($request->search, function ($q, $search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->whereDate('created_at', '>=', $date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->whereDate('created_at', '<=', $date);
        });

        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        // 🚨 CAMBIO: Validamos datos directos, no user_id
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'document_id' => 'nullable|string|max:50',
            'default_commission_rate' => 'nullable|numeric|min:0',
        ]);
        
        $broker = Broker::create($validated);
        
        return response()->json($broker, 201);
    }

    public function show(Broker $broker)
    {
        return $broker;
    }

    public function update(Request $request, Broker $broker)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'document_id' => 'nullable|string|max:50',
            'default_commission_rate' => 'nullable|numeric|min:0',
        ]);
        
        $broker->update($validated);
        
        return response()->json($broker);
    }

    public function destroy(Broker $broker)
    {
        $broker->delete();
        return response()->noContent();
    }
}