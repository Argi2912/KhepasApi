<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Services\TransactionService; 
use Illuminate\Http\Request;

class InvestorController extends Controller
{
    protected $transactionService; 

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Retorna lista de inversionistas activos para el selector.
     */
    public function index(Request $request)
    {
        $query = Investor::query()
            // ✅ CORRECCIÓN AQUÍ: Se agregó 'available_balance' a la lista
            ->select('id', 'name', 'alias', 'email', 'phone', 'is_active', 'created_at', 'available_balance')
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

        // 6. Paginar 
        $investors = $query->latest()->paginate(15);

        // Recorremos los resultados para agregar el saldo "al vuelo"
        $investors->getCollection()->transform(function ($investor) {
            // Ahora esto funcionará porque 'available_balance' ya fue seleccionado arriba
            $investor->current_balance = $investor->available_balance;
            return $investor;
        });

        return $investors;
    }

    public function show(Investor $investor)
    {
        // Adjuntar saldo antes de devolver
        $investor->current_balance = $investor->available_balance;

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
            'interest_rate' => 'required|numeric|min:0',
            'payout_day'    => 'required|integer|min:1|max:31',
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
            'interest_rate' => 'required|numeric|min:0',
            'payout_day'    => 'required|integer|min:1|max:31',
        ]);

        $investor->update($request->all());
        return response()->json($investor);
    }

    public function destroy(Investor $investor)
    {
        $investor->update(['is_active' => false]); 
        return response()->json(['message' => 'Eliminado']);
    }

    /**
     * Agregar Capital/Saldo manualmente
     */
    public function addBalance(Request $request, Investor $investor)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255'
        ]);

        $this->transactionService->addBalanceToEntity(
            $investor, 
            $request->amount, 
            $request->description ?? 'Aporte de capital / Saldo'
        );

        return response()->json([
            'message' => 'Fondeo registrado correctamente',
            'new_balance' => $investor->available_balance
        ]);
    }
}