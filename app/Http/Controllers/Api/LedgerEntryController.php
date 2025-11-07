<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule; // Importante para validar 'status' y 'type'

class LedgerEntryController extends Controller
{
    /**
     * Muestra una lista paginada y filtrable de asientos contables.
     * * Este endpoint es la base para las vistas "Por Pagar" y "Por Cobrar".
     * - Por Pagar: /api/ledger?type=payable&status=pending
     * - Por Cobrar: /api/ledger?type=receivable&status=pending
     * - Pagado:     /api/ledger?status=paid
     */
    public function index(Request $request)
    {
        // 1. Validar los filtros
        $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'paid'])],
            'type' => ['nullable', 'string', Rule::in(['payable', 'receivable'])],
            'entity_type' => 'nullable|string', // Ej: 'Broker', 'Provider'
            'entity_id' => 'nullable|integer',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // 2. Iniciar la consulta (con relaciones polimórficas)
        $query = LedgerEntry::query()->with('entity', 'transaction');

        // 3. Aplicar scopes dinámicamente
        $query->when($request->status, function ($q, $status) {
            return $q->status($status); // Llama al scopeStatus()
        });

        $query->when($request->type, function ($q, $type) {
            return $q->type($type); // Llama al scopeType()
        });

        // Filtro polimórfico (solo si ambos parámetros están presentes)
        $query->when($request->entity_type && $request->entity_id, function ($q) use ($request) {
            
            // Mapea un string simple (ej: 'Broker') a su clase completa
            $entityClass = $this->mapEntityType($request->entity_type);
            
            if ($entityClass) {
                return $q->entity($entityClass, $request->entity_id);
            }
            return $q;
        });

        $query->when($request->start_date, function ($q, $date) {
            return $q->fromDate($date);
        });

        $query->when($request->end_date, function ($q, $date) {
            return $q->toDate($date);
        });

        // 4. Paginar resultados
        return $query->latest()->paginate(15)->withQueryString();
    }

    /**
     * Muestra un asiento contable específico.
     * Carga las relaciones para dar contexto completo.
     */
    public function show(LedgerEntry $ledgerEntry)
    {
        return $ledgerEntry->load('entity', 'transaction');
    }

    /**
     * Actualiza un asiento contable.
     * * El uso principal es para marcar un asiento 'pending' como 'paid'.
     */
    public function update(Request $request, LedgerEntry $ledgerEntry)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in(['pending', 'paid'])],
            // No permitimos actualizar 'amount' o 'type' desde aquí.
        ]);

        $ledgerEntry->update($validated);
        
        // (Opcional) Registrar en el log de auditoría si se paga
        if ($validated['status'] === 'paid') {
            activity()
                ->performedOn($ledgerEntry)
                ->causedBy(auth()->user())
                ->log("Asiento #{$ledgerEntry->id} marcado como PAGADO");
        }

        return response()->json($ledgerEntry);
    }

    /**
     * Mapea un string simple (ej: 'Broker') a su nombre de clase completo.
     * Esto hace la API más amigable.
     *
     * @param string $type
     * @return string|null
     */
    private function mapEntityType($type)
    {
        $map = [
            'Broker' => \App\Models\Broker::class,
            'Provider' => \App\Models\Provider::class,
            'Client' => \App\Models\Client::class,
            'User' => \App\Models\User::class,
        ];

        // Acepta tanto 'Broker' como 'App\Models\Broker'
        if (in_array($type, $map)) {
            return $type;
        }

        return $map[$type] ?? null;
    }

    // store() y destroy() se omiten intencionalmente
    // para mantener la integridad de la auditoría contable.
}