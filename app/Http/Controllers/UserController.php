<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all();

        return response()->json(['message' => 'Usuarios obtenidos con éxito', 'data' => $users], 200);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El campo de correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser una dirección de correo electrónico válida.',
            'email.unique' => 'El correo electrónico ya ha sido tomado.',
            'password.required' => 'El campo de contraseña es obligatorio.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();

            $data['password'] = bcrypt($data['password']);
            User::create($data);

            DB::commit();

            return response()->json(['message' => 'Usuario creado con éxito'], 201);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => 'Error al crear el usuario'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|required|string|min:6',
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El campo de correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico debe ser una dirección de correo electrónico válida.',
            'email.unique' => 'El correo electrónico ya ha sido tomado.',
            'password.required' => 'El campo de contraseña es obligatorio.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();

            if (isset($data['password'])) {
                $data['password'] = bcrypt($data['password']);
            }

            $user = User::findOrFail($id);
            $user->update($data);

            DB::commit();

            return response()->json(['message' => 'Usuario actualizado con éxito'], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => 'Error al actualizar el usuario'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);
            $user->delete();

            DB::commit();

            return response()->json(['message' => 'Usuario eliminado con éxito'], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => 'Error al eliminar el usuario'], 500);
        }
    }

    public function changeStatus(Request $request, $id)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'status' => 'required|in:active,inactive,suspended',
        ], [
            'status.required' => 'El campo de estado es obligatorio.',
            'status.in' => 'El estado debe ser uno de los siguientes: active, inactive, suspended.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);
            $user->status = $data['status'];
            $user->save();

            DB::commit();

            return response()->json(['message' => 'Estado del usuario actualizado con éxito'], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => 'Error al actualizar el estado del usuario'], 500);
        }
    }
}
