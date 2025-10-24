<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Validation\Rule;

class PermissionController extends Controller
{

   
    public function index()
    {
        $permissions = Permission::all();
        $roles = Role::all();
        $users = User::with('roles', 'permissions')->get(); 

        return response()->json([
            'permissions' => $permissions,
            'roles' => $roles,
            'users' => $users
        ]);
        
    }


    public function createPermission(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name']
        ]);

        $permission = Permission::create(['name' => $request->name]);

        return response()->json([
            'message' => 'Permiso creado exitosamente',
            'permission' => $permission
        ], 201);
    }

    
    public function createRole(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name']
        ]);

        $role = Role::create(['name' => $request->name]);

        return response()->json([
            'message' => 'Rol creado exitosamente',
            'role' => $role
        ], 201); 
    }

    public function assignRolePermissions(Request $request)
    {
        $request->validate([
            'role' => ['required', 'exists:roles,id'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id']
        ]);

        $role = Role::findById($request->role);
        $role->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permisos asignados exitosamente',
            'role' => $role->load('permissions')
        ]);
    }
 
    public function updateRole(Request $request, $id)
{
    try {
        $role = Role::findOrFail($id);
        $role->update([
            'name' => $request->name,
            'guard_name' => $request->guard_name ?? 'web',
        ]);

        return response()->json($role, 200);
    } catch (\Exception $e) {
        return response()->json(['message' => 'Error al actualizar el rol', 'error' => $e->getMessage()], 500);
    }
}

   
    public function assignUserRoles(Request $request)
    {
        $request->validate([
            'user' => ['required', 'exists:users,id'],
            'roles' => ['required', 'array'],
            'roles.*' => ['exists:roles,id']
        ]);

        $user = User::findOrFail($request->user);
        $user->syncRoles($request->roles);

        return response()->json([
            'message' => 'Roles asignados exitosamente',
            'user' => $user->load('roles') 
        ]);
    }

   
    public function assignUserDirectPermissions(Request $request)
    {
        $request->validate([
            'user' => ['required', 'exists:users,id'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id']
        ]);

        $user = User::findOrFail($request->user);
        $user->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permisos directos asignados exitosamente',
            'user' => $user->load('permissions') 
        ]);
    }
}