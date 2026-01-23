<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return $query->latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'salary_amount' => 'required|numeric|min:0',
            'currency_code' => 'required|string|size:3',
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
    
    public function show(Employee $employee)
    {
        return response()->json($employee);
    }

    /**
     * PROCESAR NÓMINA (Actualizado)
     * Puede procesar un empleado específico o todos.
     */
    public function processPayroll(Request $request)
    {
        // Validamos si nos envían un ID específico
        $request->validate([
            'employee_id' => 'nullable|exists:employees,id'
        ]);

        return DB::transaction(function () use ($request) {
            
            // 1. Decidir a quién procesar
            $query = Employee::where('is_active', true);

            if ($request->filled('employee_id')) {
                // Si enviaron ID, filtramos solo ese
                $query->where('id', $request->employee_id);
                $mode = "individual";
            } else {
                $mode = "masiva";
            }

            $employees = $query->get();
            
            if ($employees->isEmpty()) {
                return response()->json(['message' => 'No hay empleados activos para procesar.'], 422);
            }

            $count = 0;
            $processedNames = [];

            foreach ($employees as $employee) {
                $amount = $employee->salary_amount; 

                // Creamos la deuda (Pasivo / Payable) en el Ledger
                $employee->ledgerEntries()->create([
                    'tenant_id' => $employee->tenant_id ?? 1,
                    'type' => 'payable', 
                    'status' => 'pending',
                    'amount' => $amount,         
                    'original_amount' => $amount, 
                    'pending_amount' => $amount,  
                    'paid_amount' => 0,
                    'currency_code' => $employee->currency_code ?? 'USD',
                    'description' => 'Nómina: ' . now()->format('d/m/Y'),
                    'due_date' => now()->addDays(1), 
                ]);
                
                $count++;
                $processedNames[] = $employee->name;
            }

            // Mensaje personalizado según el modo
            if ($mode === 'individual') {
                $msg = "Nómina generada exitosamente para: " . implode(", ", $processedNames);
            } else {
                $msg = "Nómina masiva procesada para $count empleados.";
            }

            return response()->json([
                'message' => $msg,
                'status' => 'success',
                'count' => $count
            ]);
        });
    }
}