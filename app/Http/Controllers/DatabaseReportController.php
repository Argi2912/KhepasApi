<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Provider;
use App\Models\Corredor;
use App\Models\Admin;
use App\Models\Request as Solicitud;
use Exception;

use App\Http\Resources\ClientResource;
use App\Http\Resources\ProviderResource;
use App\Http\Resources\CorredorResource;
use App\Http\Resources\AdminResource;
use App\Http\Resources\RequestResource;

class DatabaseReportController extends Controller
{
    private function buildQuery(Request $request, $modelQuery, array $searchFields)
    {
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $modelQuery->where(function ($q) use ($searchTerm, $searchFields) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'like', "%{$searchTerm}%");
                }
            });
        }

        if ($request->has('status')) {
            $modelQuery->where('status', $request->status);
        }

        return $modelQuery;
    }

    public function getClients(Request $request)
    {
        try {
            $query = Client::query();
            $searchFields = ['first_name', 'last_name', 'email', 'company_name'];
            $clients = $this->buildQuery($request, $query, $searchFields)
                            ->paginate($request->get('per_page', 15));

            return ClientResource::collection($clients);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error al obtener clientes', 'error' => $e->getMessage()], 500);
        }
    }

    public function getProviders(Request $request)
    {
        try {
            $query = Provider::query();
            $searchFields = ['name', 'email', 'phone', 'type'];
            $providers = $this->buildQuery($request, $query, $searchFields)
                              ->paginate($request->get('per_page', 15));
            
            return ProviderResource::collection($providers);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error al obtener proveedores', 'error' => $e->getMessage()], 500);
        }
    }

    public function getBrokers(Request $request)
    {
        try {
            $query = Corredor::query(); 
            $searchFields = ['name', 'email', 'phone'];
            $brokers = $this->buildQuery($request, $query, $searchFields)
                            ->paginate($request->get('per_page', 15));
            
            return CorredorResource::collection($brokers);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error al obtener corredores', 'error' => $e->getMessage()], 500);
        }
    }

    public function getAdmins(Request $request)
    {
        try {
            $query = Admin::query();
            $searchFields = ['name', 'email'];
            $admins = $this->buildQuery($request, $query, $searchFields)
                           ->paginate($request->get('per_page', 15));
            
            return AdminResource::collection($admins);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error al obtener administradores', 'error' => $e->getMessage()], 500);
        }
    }

    public function getRequests(Request $request)
    {
        try {
            $query = Solicitud::query()->with(['client', 'provider', 'currency']);
            $searchFields = ['status']; 
            
            if ($request->has('client_id')) {
                $query->where('client_id', $request->client_id);
            }

            $requests = $this->buildQuery($request, $query, $searchFields)
                             ->paginate($request->get('per_page', 15));
            
            return RequestResource::collection($requests);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error al obtener solicitudes', 'error' => $e->getMessage()], 500);
        }
    }
}