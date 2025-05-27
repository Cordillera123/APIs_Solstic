<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class UsuarioController extends Controller
{
    /**
     * Obtener todos los usuarios con paginación
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $perfilId = $request->get('perfil_id', '');
            $estadoId = $request->get('estado_id', '');

            $query = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_nom2',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_ape2',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_usu.usu_tel',
                    'tbl_usu.usu_dir',
                    'tbl_per.per_nom as perfil',
                    'tbl_est.est_nom as estado',
                    'tbl_usu.per_id',
                    'tbl_usu.est_id',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
                );

            // Filtros
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('tbl_usu.usu_nom', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_usu.usu_ape', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_usu.usu_cor', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_usu.usu_ced', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_per.per_nom', 'ILIKE', "%{$search}%");
                });
            }

            if (!empty($perfilId)) {
                $query->where('tbl_usu.per_id', $perfilId);
            }

            if (!empty($estadoId)) {
                $query->where('tbl_usu.est_id', $estadoId);
            }

            $usuarios = $query->orderBy('tbl_usu.usu_id', 'desc')
                            ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $usuarios
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuarios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nuevo usuario
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'usu_nom' => 'required|string|max:100',
            'usu_nom2' => 'nullable|string|max:100',
            'usu_ape' => 'required|string|max:100',
            'usu_ape2' => 'nullable|string|max:100',
            'usu_cor' => 'required|email|unique:tbl_usu,usu_cor|max:100',
            'usu_ced' => 'required|string|unique:tbl_usu,usu_ced|max:10',
            'usu_con' => 'required|string|min:6|max:64',
            'usu_tel' => 'nullable|string|max:10',
            'usu_dir' => 'nullable|string|max:100',
            'per_id' => 'required|integer|exists:tbl_per,per_id',
            'est_id' => 'required|integer|exists:tbl_est,est_id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $usuarioData = $request->all();
            
            // Hashear la contraseña
            $usuarioData['usu_con'] = Hash::make($request->usu_con);

            $usuario = Usuario::create($usuarioData);

            // Obtener el usuario creado con relaciones
            $usuarioCompleto = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->select(
                    'tbl_usu.*',
                    'tbl_per.per_nom as perfil',
                    'tbl_est.est_nom as estado'
                )
                ->where('tbl_usu.usu_id', $usuario->usu_id)
                ->first();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario creado exitosamente',
                'data' => $usuarioCompleto
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuario específico
     */
    public function show($id)
    {
        try {
            $usuario = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->select(
                    'tbl_usu.*',
                    'tbl_per.per_nom as perfil',
                    'tbl_est.est_nom as estado'
                )
                ->where('tbl_usu.usu_id', $id)
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Ocultar contraseña
            unset($usuario->usu_con);

            return response()->json([
                'status' => 'success',
                'data' => $usuario
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar usuario
     */
    public function update(Request $request, $id)
    {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'usu_nom' => 'sometimes|required|string|max:100',
            'usu_nom2' => 'nullable|string|max:100',
            'usu_ape' => 'sometimes|required|string|max:100',
            'usu_ape2' => 'nullable|string|max:100',
            'usu_cor' => 'sometimes|required|email|unique:tbl_usu,usu_cor,' . $id . ',usu_id|max:100',
            'usu_ced' => 'sometimes|required|string|unique:tbl_usu,usu_ced,' . $id . ',usu_id|max:10',
            'usu_con' => 'nullable|string|min:6|max:64',
            'usu_tel' => 'nullable|string|max:10',
            'usu_dir' => 'nullable|string|max:100',
            'per_id' => 'sometimes|required|integer|exists:tbl_per,per_id',
            'est_id' => 'sometimes|required|integer|exists:tbl_est,est_id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $usuarioData = $request->all();
            
            // Si se proporciona nueva contraseña, hashearla
            if (!empty($request->usu_con)) {
                $usuarioData['usu_con'] = Hash::make($request->usu_con);
            } else {
                // Si no se proporciona contraseña, no actualizarla
                unset($usuarioData['usu_con']);
            }

            $usuario->update($usuarioData);

            // Obtener el usuario actualizado con relaciones
            $usuarioCompleto = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->select(
                    'tbl_usu.*',
                    'tbl_per.per_nom as perfil',
                    'tbl_est.est_nom as estado'
                )
                ->where('tbl_usu.usu_id', $id)
                ->first();

            // Ocultar contraseña
            unset($usuarioCompleto->usu_con);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario actualizado exitosamente',
                'data' => $usuarioCompleto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar usuario
     */
    public function destroy($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            // Eliminar tokens de acceso del usuario
            $usuario->tokens()->delete();
            
            // Eliminar usuario
            $usuario->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado del usuario (activar/desactivar)
     */
    public function toggleStatus($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            // Alternar entre estado activo (1) e inactivo (2)
            // Asumiendo que 1 = Activo, 2 = Inactivo
            $nuevoEstado = ($usuario->est_id == 1) ? 2 : 1;
            $usuario->est_id = $nuevoEstado;
            $usuario->save();

            // Si se desactiva el usuario, revocar todos sus tokens
            if ($nuevoEstado == 2) {
                $usuario->tokens()->delete();
            }

            DB::commit();

            // Obtener nombre del estado
            $estadoNombre = DB::table('tbl_est')
                ->where('est_id', $nuevoEstado)
                ->value('est_nom');

            return response()->json([
                'status' => 'success',
                'message' => "Usuario {$estadoNombre} exitosamente",
                'new_status' => [
                    'id' => $nuevoEstado,
                    'nombre' => $estadoNombre
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar contraseña del usuario
     */
    public function changePassword(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Verificar contraseña actual
            if (!Hash::check($request->current_password, $usuario->usu_con)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La contraseña actual es incorrecta'
                ], 400);
            }

            DB::beginTransaction();

            // Actualizar contraseña
            $usuario->usu_con = Hash::make($request->new_password);
            $usuario->save();

            // Revocar todos los tokens existentes para forzar nuevo login
            $usuario->tokens()->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña actualizada exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar contraseña: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resetear contraseña del usuario (solo para administradores)
     */
    public function resetPassword(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:6'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            // Actualizar contraseña
            $usuario->usu_con = Hash::make($request->new_password);
            $usuario->save();

            // Revocar todos los tokens existentes
            $usuario->tokens()->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña reseteada exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al resetear contraseña: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener opciones para formularios
     */
    public function getFormOptions()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->select('per_id as value', 'per_nom as label')
                ->get();

            $estados = DB::table('tbl_est')
                ->select('est_id as value', 'est_nom as label')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'perfiles' => $perfiles,
                    'estados' => $estados
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener opciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos del usuario
     */
    public function getUserPermissions($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Usar el método del AuthController para obtener permisos
            $authController = new AuthController();
            $permisos = $authController->getUserMenus($id);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'usuario_id' => $id,
                    'perfil_id' => $usuario->per_id,
                    'permisos' => $permisos
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos: ' . $e->getMessage()
            ], 500);
        }
    }


// Agregar estos métodos al UsuarioController.php existente

/**
 * Obtener permisos detallados de un usuario específico
 */
public function getPermissionsDetail($id)
{
    try {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener permisos del perfil del usuario
        $permisosPerfil = DB::table('tbl_perm')
            ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
            ->leftJoin('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
            ->leftJoin('tbl_ico as ico_men', 'tbl_men.ico_id', '=', 'ico_men.ico_id')
            ->leftJoin('tbl_ico as ico_sub', 'tbl_sub.ico_id', '=', 'ico_sub.ico_id')
            ->leftJoin('tbl_ico as ico_opc', 'tbl_opc.ico_id', '=', 'ico_opc.ico_id')
            ->where('tbl_perm.per_id', $usuario->per_id)
            ->where('tbl_men.men_est', true)
            ->select(
                'tbl_men.men_id', 'tbl_men.men_nom', 'tbl_men.men_componente',
                'ico_men.ico_nom as men_icon_nombre',
                'tbl_sub.sub_id', 'tbl_sub.sub_nom', 'tbl_sub.sub_componente', 'tbl_sub.sub_est',
                'ico_sub.ico_nom as sub_icon_nombre',
                'tbl_opc.opc_id', 'tbl_opc.opc_nom', 'tbl_opc.opc_componente', 'tbl_opc.opc_est',
                'ico_opc.ico_nom as opc_icon_nombre'
            )
            ->get();

        // Obtener permisos individuales del usuario (si los hubiera)
        $permisosUsuario = DB::table('tbl_usu_perm')
            ->where('usu_id', $id)
            ->get();

        // Organizar permisos del perfil en estructura de árbol
        $menuTree = [];
        
        foreach ($permisosPerfil as $item) {
            // Crear menú si no existe
            if (!isset($menuTree[$item->men_id])) {
                $menuTree[$item->men_id] = [
                    'men_id' => $item->men_id,
                    'men_nom' => $item->men_nom,
                    'men_componente' => $item->men_componente,
                    'ico_nombre' => $item->men_icon_nombre,
                    'has_permission' => true, // Siempre true porque viene de permisos del perfil
                    'submenus' => []
                ];
            }
            
            // Agregar submenú si existe y está activo
            if ($item->sub_id && $item->sub_est) {
                $submenuKey = $item->sub_id;
                
                if (!isset($menuTree[$item->men_id]['submenus'][$submenuKey])) {
                    $menuTree[$item->men_id]['submenus'][$submenuKey] = [
                        'sub_id' => $item->sub_id,
                        'sub_nom' => $item->sub_nom,
                        'sub_componente' => $item->sub_componente,
                        'ico_nombre' => $item->sub_icon_nombre,
                        'has_permission' => true,
                        'opciones' => []
                    ];
                }
                
                // Agregar opción si existe y está activa
                if ($item->opc_id && $item->opc_est) {
                    $menuTree[$item->men_id]['submenus'][$submenuKey]['opciones'][] = [
                        'opc_id' => $item->opc_id,
                        'opc_nom' => $item->opc_nom,
                        'opc_componente' => $item->opc_componente,
                        'ico_nombre' => $item->opc_icon_nombre,
                        'has_permission' => true
                    ];
                }
            }
        }

        // Convertir submenus de asociativo a indexado
        foreach ($menuTree as &$menu) {
            $menu['submenus'] = array_values($menu['submenus']);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'usuario' => [
                    'usu_id' => $usuario->usu_id,
                    'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_nom2} {$usuario->usu_ape} {$usuario->usu_ape2}"),
                    'usu_cor' => $usuario->usu_cor,
                    'per_id' => $usuario->per_id,
                    'perfil' => $usuario->perfil ? $usuario->perfil->per_nom : null
                ],
                'permisos_perfil' => array_values($menuTree),
                'permisos_usuario' => $permisosUsuario
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener permisos del usuario: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Asignar permisos específicos a un usuario
 */
public function assignPermissions(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'permissions' => 'required|array',
        'permissions.*.men_id' => 'required|integer|exists:tbl_men,men_id',
        'permissions.*.sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
        'permissions.*.opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
        'permissions.*.grant' => 'required|boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Datos de validación incorrectos',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        DB::beginTransaction();

        $processedCount = 0;
        
        foreach ($request->permissions as $permission) {
            $menId = $permission['men_id'];
            $subId = $permission['sub_id'];
            $opcId = $permission['opc_id'];
            $grant = $permission['grant'];

            // Verificar que el permiso esté disponible en el perfil del usuario
            $perfilHasPermission = DB::table('tbl_perm')
                ->where('per_id', $usuario->per_id)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->exists();

            if (!$perfilHasPermission) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede asignar un permiso que no está disponible en el perfil del usuario'
                ], 400);
            }

            // Como los permisos de usuario son un subconjunto de los permisos del perfil,
            // en lugar de crear una tabla separada, podemos usar un enfoque diferente:
            // Crear registros específicos para este usuario que "activen" los permisos del perfil

            $userPermissionData = [
                'usu_id' => $id,
                'men_id' => $menId,
                'sub_id' => $subId,
                'opc_id' => $opcId
            ];

            // Verificar si ya existe este permiso específico para el usuario
            $existingUserPermission = DB::table('tbl_usu_perm')
                ->where($userPermissionData)
                ->first();

            if ($grant && !$existingUserPermission) {
                // Otorgar permiso específico al usuario
                DB::table('tbl_usu_perm')->insert($userPermissionData);
                $processedCount++;
            } elseif (!$grant && $existingUserPermission) {
                // Revocar permiso específico del usuario
                DB::table('tbl_usu_perm')
                    ->where($userPermissionData)
                    ->delete();
                $processedCount++;
            }
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => "Se procesaron {$processedCount} cambios de permisos correctamente"
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al asignar permisos: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Obtener permisos activos de un usuario (combinando perfil + individuales)
 */
public function getActivePermissions($id)
{
    try {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener permisos disponibles del perfil
        $permisosDisponibles = DB::table('tbl_perm')
            ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
            ->leftJoin('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
            ->where('tbl_perm.per_id', $usuario->per_id)
            ->where('tbl_men.men_est', true)
            ->select(
                'tbl_perm.men_id',
                'tbl_perm.sub_id', 
                'tbl_perm.opc_id',
                'tbl_men.men_nom',
                'tbl_sub.sub_nom',
                'tbl_opc.opc_nom'
            )
            ->get();

        // Obtener permisos específicamente asignados al usuario
        $permisosUsuario = DB::table('tbl_usu_perm')
            ->where('usu_id', $id)
            ->get();

        // Crear conjunto de permisos activos del usuario
        $permisosActivos = [];
        
        foreach ($permisosDisponibles as $permisoDisponible) {
            // Verificar si el usuario tiene este permiso específico activado
            $tienePermiso = $permisosUsuario->contains(function ($permisoUser) use ($permisoDisponible) {
                return $permisoUser->men_id == $permisoDisponible->men_id &&
                       $permisoUser->sub_id == $permisoDisponible->sub_id &&
                       $permisoUser->opc_id == $permisoDisponible->opc_id;
            });

            if ($tienePermiso) {
                $permisosActivos[] = [
                    'men_id' => $permisoDisponible->men_id,
                    'sub_id' => $permisoDisponible->sub_id,
                    'opc_id' => $permisoDisponible->opc_id,
                    'men_nom' => $permisoDisponible->men_nom,
                    'sub_nom' => $permisoDisponible->sub_nom,
                    'opc_nom' => $permisoDisponible->opc_nom
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'usuario_id' => $id,
                'permisos_activos' => $permisosActivos,
                'total_disponibles' => $permisosDisponibles->count(),
                'total_activos' => count($permisosActivos)
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener permisos activos: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Copiar permisos de un usuario a otro (del mismo perfil)
 */
public function copyUserPermissions(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'target_user_id' => 'required|integer|exists:tbl_usu,usu_id|different:' . $id,
        'overwrite' => 'boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Datos de validación incorrectos',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $usuarioOrigen = Usuario::find($id);
        $usuarioDestino = Usuario::find($request->target_user_id);
        
        if (!$usuarioOrigen || !$usuarioDestino) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Verificar que ambos usuarios tengan el mismo perfil
        if ($usuarioOrigen->per_id !== $usuarioDestino->per_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden copiar permisos entre usuarios del mismo perfil'
            ], 400);
        }

        DB::beginTransaction();

        $overwrite = $request->overwrite ?? false;

        // Si se especifica sobrescribir, eliminar permisos existentes del usuario destino
        if ($overwrite) {
            DB::table('tbl_usu_perm')
                ->where('usu_id', $request->target_user_id)
                ->delete();
        }

        // Obtener permisos del usuario origen
        $permisosOrigen = DB::table('tbl_usu_perm')
            ->where('usu_id', $id)
            ->get();

        $copiedCount = 0;

        // Copiar cada permiso
        foreach ($permisosOrigen as $permiso) {
            $newPermission = [
                'usu_id' => $request->target_user_id,
                'men_id' => $permiso->men_id,
                'sub_id' => $permiso->sub_id,
                'opc_id' => $permiso->opc_id
            ];

            // Verificar si ya existe (solo si no se sobrescribe)
            if (!$overwrite) {
                $exists = DB::table('tbl_usu_perm')
                    ->where($newPermission)
                    ->exists();
                
                if ($exists) {
                    continue;
                }
            }

            DB::table('tbl_usu_perm')->insert($newPermission);
            $copiedCount++;
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => "Se copiaron {$copiedCount} permisos correctamente"
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al copiar permisos: ' . $e->getMessage()
        ], 500);
    }
}
}