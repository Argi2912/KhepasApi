<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use Illuminate\Http\Request;

class InvestorController extends Controller
{
    /**
     * Retorna lista de inversionistas activos para el selector.
     */
    public function index(Request $request)
    {
        $query = Investor::query()
            ->select('id', 'name', 'alias', 'email', 'phone', 'is_active', 'created_at')
            ->where('is_active', true);

        // Opcional: permitir búsqueda
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('alias', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate(15);
    }

    public function show(Investor $investor)
    {
        return response()->json($investor);
    }

    /**
     * Crea un nuevo inversionista.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'alias' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        $investor = Investor::create($data);

        return response()->json($investor, 201);
    }

    public function update(Request $request, Investor $investor)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'alias'     => 'nullable|string|max:255',
            'email'     => 'nullable|email',
            'phone'     => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        $investor->update($request->all());
        return response()->json($investor);
    }

    public function destroy(Investor $investor)
    {
        $investor->update(['is_active' => false]); // o delete() si prefieres borrado físico
                                                   // $investor->delete();
        return response()->json(['message' => 'Eliminado']);
    }
}
