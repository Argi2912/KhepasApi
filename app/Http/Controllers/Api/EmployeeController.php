<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query();

        if ($request->has('search')) {
            $val = $request->search;
            $query->where('name', 'like', "%{$val}%")
                  ->orWhere('position', 'like', "%{$val}%");
        }

        // Paginamos
        $employees = $query->latest()->paginate(15);
        
        // Adjuntamos el saldo "al vuelo" para verlo en la tabla
        $employees->getCollection()->transform(function ($emp) {
            $emp->pending_balance = $emp->pending_salary;
            return $emp;
        });

        return $employees;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'salary_amount' => 'required|numeric|min:0',
            'currency_code' => 'required|string|size:3', // USD, VES, EUR
            'payment_frequency' => 'required|in:weekly,biweekly,monthly',
            'position' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'payment_day_1' => 'nullable|integer',
            'payment_day_2' => 'nullable|integer',
        ]);

        $employee = Employee::create($data);
        return response()->json($employee, 201);
    }

    /**
     * Cargar saldo manual (Billetera)
     */
    public function addBalance(Request $request, Employee $employee)
    {
        // 1. Validar
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'currency' => 'required|string|size:3' 
        ]);

        // 2. Usar el servicio (Asegúrate de tener TransactionService inyectado en el constructor)
        // Si no tienes el servicio inyectado, puedes hacerlo rápido así:
        $service = app(\App\Services\TransactionService::class);
        
        $service->addBalanceToEntity(
            $employee, 
            $request->amount, 
            $request->description ?? 'Pago / Adelanto de Nómina',
            $request->currency
        );

        return response()->json(['message' => 'Saldo registrado correctamente']);
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($request->all());
        return response()->json($employee);
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return response()->json(['message' => 'Empleado eliminado']);
    }
    
    // Ver un solo empleado
    public function show(Employee $employee)
    {
        $employee->pending_balance = $employee->pending_salary;
        return response()->json($employee);
    }
    public function processPayroll(Request $request)
{
    // 1. Buscar empleados activos
    $employees = Employee::where('is_active', true)->get();
    
    $count = 0;
    
    foreach ($employees as $employee) {
        // 2. Aquí podrías calcular si es quincenal o mensual.
        // Por ahora, asumiremos que se genera el monto completo de su salario base.
        $amount = $employee->salary_amount; 

        // 3. Crear la entrada en el Libro Mayor (Ledger)
        // ESTO ES LO QUE "ACTIVA" LA DEUDA EN ROJO
        $employee->ledgerEntries()->create([
            'tenant_id' => $employee->tenant_id ?? 1, // Tu ID de sucursal
            'type' => 'payable', // Payable = La empresa DEBE dinero (Pasivo)
            'status' => 'pending',
            
            // Montos
            'amount' => $amount,         // Monto del movimiento
            'original_amount' => $amount, // Deuda original
            'pending_amount' => $amount,  // Lo que se debe (al inicio es todo)
            'paid_amount' => 0,
            
            'currency_code' => $employee->currency_code ?? 'USD',
            'description' => 'Nómina Generada: ' . now()->format('d/m/Y'),
            'due_date' => now()->addDays(1), // Vence mañana
        ]);
        
        $count++;
    }

    return response()->json([
        'message' => "Nómina procesada para $count empleados",
        'status' => 'success'
    ]);
}

}

