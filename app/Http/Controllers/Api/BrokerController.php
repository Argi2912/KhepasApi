<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Broker;
use Illuminate\Http\Request; // Reemplaza con Form Requests

class BrokerController extends Controller
{
    public function index(Request $request) // 2. Inyectar Request
    {
        // 3. Validar
        $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 4. Iniciar consulta
        $query = Broker::query()->with('user:id,name');

        // 5. Aplicar scopes
        $query->when($request->user_id, function ($q, $id) {
            return $q->userId($id); // Llama al scopeUserId()
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date);
        });

        // 6. Paginar
        return $query->latest()->paginate(15)->withQueryString();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Asume que el 'user_id' ya existe y tiene rol 'corredor'
            'user_id' => 'required|exists:users,id', 
            'default_commission_rate' => 'nullable|numeric|min:0',
        ]);
        
        $broker = Broker::create($validated);
        
        return response()->json($broker->load('user:id,name'), 201);
    }

    public function show(Broker $broker)
    {
        return $broker->load('user:id,name');
    }

    public function update(Request $request, Broker $broker)
    {
        $validated = $request->validate([
            'user_id' => 'sometimes|required|exists:users,id',
            'default_commission_rate' => 'nullable|numeric|min:0',
        ]);
        
        $broker->update($validated);
        
        return response()->json($broker->load('user:id,name'));
    }

    public function destroy(Broker $broker)
    {
        $broker->delete();
        return response()->noContent();
    }
}