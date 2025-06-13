<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserButtonPermissionController extends Controller
{
    /**
     * Obtener todos los usuarios de un perfil específico
     */
    public function getUsersByProfile($perfilId)
    {
        try {
            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            $usuarios = DB::table('tbl_usu')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->where('tbl_usu.per_id', $perfilId)
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_est.est_nom',
                    'tbl_usu.usu_ultimo_acceso'
                )
                ->orderBy('tbl_usu.usu_nom')
                ->get();

            // Contar personalizaciones para cada usuario
            foreach ($usuarios as $usuario) {
                $permisosPersonalizados = DB::table('tbl_perm_bot_usuario')
                    ->where('usu_id', $usuario->usu_id)
                    ->where('perm_bot_usu_activo', true)
                    ->count();

                $usuario->permisos_personalizados = $permisosPersonalizados;
            }

            return response()->json([
                'status' => 'success',
                'perfil' => $perfil,
                'usuarios' => $usuarios,
                'total_usuarios' => $usuarios->count()
            ]);

        } catch (\Exception $e) {
            Log::error("Error obteniendo usuarios del perfil {$perfilId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener estructura de botones para un usuario específico
     * Combina permisos del perfil + personalizaciones del usuario
     */
    public function getUserButtonPermissions($usuarioId)
    {
        try {
            $usuario = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'tbl_per.per_nom')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            if (!$usuario->per_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario no tiene perfil asignado'
                ], 422);
            }

            Log::info("🔍 Obteniendo permisos para usuario {$usuarioId} con perfil {$usuario->per_id}");

            // ✅ CORRECCIÓN: Obtener solo módulos a los que el usuario tiene acceso
            $menuStructure = $this->getUserAccessibleModulesWithButtons($usuarioId, $usuario->per_id);

            Log::info("📋 Módulos accesibles obtenidos: " . count($menuStructure) . " elementos");

            return response()->json([
                'status' => 'success',
                'usuario' => [
                    'usu_id' => $usuario->usu_id,
                    'usu_nom' => $usuario->usu_nom,
                    'usu_ape' => $usuario->usu_ape,
                    'usu_cor' => $usuario->usu_cor,
                    'per_id' => $usuario->per_id,
                    'per_nom' => $usuario->per_nom
                ],
                'menu_structure' => $menuStructure,
                'debug_info' => [
                    'perfil_id' => $usuario->per_id,
                    'total_modulos_accesibles' => count($menuStructure)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error obteniendo permisos del usuario {$usuarioId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Alternar permiso específico de botón para un usuario
     */
    public function toggleUserButtonPermission(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'usu_id' => 'required|integer|exists:tbl_usu,usu_id',
            'men_id' => 'required|integer|exists:tbl_men,men_id',
            'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'bot_id' => 'required|integer|exists:tbl_bot,bot_id',
            'perm_tipo' => 'required|string|in:C,D',
            'observaciones' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $usuarioId = $request->input('usu_id');
            $menId = $request->input('men_id');
            $subId = $request->input('sub_id');
            $opcId = $request->input('opc_id');
            $botId = $request->input('bot_id');
            $permTipo = $request->input('perm_tipo');
            $observaciones = $request->input('observaciones');

            // Verificar acceso al módulo
            $tieneAccesoModulo = $this->userHasModuleAccess($usuarioId, $menId, $subId, $opcId);
            
            if (!$tieneAccesoModulo) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario no tiene acceso a este módulo'
                ], 403);
            }

            // Buscar personalización existente
            $existingCustomization = DB::table('tbl_perm_bot_usuario')
                ->where('usu_id', $usuarioId)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->where('bot_id', $botId)
                ->first();

            $permissionData = [
                'usu_id' => $usuarioId,
                'men_id' => $menId,
                'sub_id' => $subId,
                'opc_id' => $opcId,
                'bot_id' => $botId,
                'perm_tipo' => $permTipo,
                'perm_bot_usu_observaciones' => $observaciones,
                'perm_bot_usu_activo' => true,
                'perm_bot_usu_edi' => now(),
                'perm_bot_usu_creado_por' => Auth::id()
            ];

            if ($existingCustomization) {
                DB::table('tbl_perm_bot_usuario')
                    ->where('perm_bot_usu_id', $existingCustomization->perm_bot_usu_id)
                    ->update($permissionData);
                $message = 'Personalización actualizada correctamente';
            } else {
                $permissionData['perm_bot_usu_cre'] = now();
                DB::table('tbl_perm_bot_usuario')->insert($permissionData);
                $message = 'Personalización creada correctamente';
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'accion' => $permTipo === 'C' ? 'concedido' : 'denegado',
                    'perm_tipo' => $permTipo,
                    'era_existente' => !is_null($existingCustomization)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en toggle user button permission: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al modificar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getUserAccessibleModulesWithButtons($usuarioId, $perfilId)
    {
        Log::info("🔍 Obteniendo módulos accesibles para usuario: {$usuarioId}, perfil: {$perfilId}");

        // 1. Obtener permisos básicos del perfil
        $permisosDelPerfil = DB::table('tbl_perm_perfil')
            ->where('per_id', $perfilId)
            ->where('perm_per_activo', true)
            ->get();

        if ($permisosDelPerfil->isEmpty()) {
            Log::warning("⚠️ Perfil {$perfilId} no tiene permisos básicos asignados");
            return [];
        }

        // 2. Obtener permisos específicos del usuario
        $permisosDelUsuario = DB::table('tbl_perm_usuario')
            ->where('usu_id', $usuarioId)
            ->where('perm_usu_activo', true)
            ->get()
            ->keyBy(function ($item) {
                return "{$item->men_id}_{$item->sub_id}_{$item->opc_id}";
            });

        Log::info("📊 Permisos del perfil: " . $permisosDelPerfil->count() . ", Permisos específicos del usuario: " . $permisosDelUsuario->count());

        $menuStructure = [];

        // 3. Procesar cada permiso del perfil
        foreach ($permisosDelPerfil as $permisoPerfil) {
            $key = "{$permisoPerfil->men_id}_{$permisoPerfil->sub_id}_{$permisoPerfil->opc_id}";
            
            // ✅ LÓGICA DE ACCESO: Verificar si el usuario tiene acceso al módulo
            $tieneAcceso = $this->evaluateUserModuleAccess($permisoPerfil, $permisosDelUsuario[$key] ?? null);
            
            if (!$tieneAcceso) {
                Log::debug("❌ Usuario no tiene acceso al módulo: {$key}");
                continue; // Saltar módulos sin acceso
            }

            // Determinar tipo y verificar si es ventana directa
            $tipoModulo = $this->determinarTipoModuloUser($permisoPerfil);
            
            if (!$tipoModulo['es_ventana_directa']) {
                continue; // Solo procesar ventanas directas
            }

            Log::info("✅ Procesando módulo accesible: {$tipoModulo['tipo']}, {$key}");

            // Obtener botones con permisos combinados
            $botones = $this->obtenerBotonesConPermisosEfectivos(
                $usuarioId, 
                $perfilId, 
                $permisoPerfil, 
                $tipoModulo['tipo']
            );

            if (empty($botones)) {
                continue; // Solo agregar módulos que tengan botones
            }

            // Agregar a la estructura
            $this->agregarModuloAEstructuraUser($menuStructure, $permisoPerfil, $botones, $tipoModulo['tipo']);
        }

        Log::info("✅ Estructura final: " . count($menuStructure) . " módulos accesibles");
        return $menuStructure;
    }
    private function evaluateUserModuleAccess($permisoPerfil, $permisoUsuario)
{
    $key = "{$permisoPerfil->men_id}_{$permisoPerfil->sub_id}_{$permisoPerfil->opc_id}";
    
    // Si no hay permiso específico del usuario, hereda del perfil
    if (!$permisoUsuario) {
        Log::debug("✅ Usuario hereda acceso del perfil para módulo: {$key}");
        return true; // El perfil ya tiene el permiso
    }

    // Si hay permiso específico, evaluar según tipo
    $tieneAcceso = false;
    switch ($permisoUsuario->perm_tipo) {
        case 'C': // Conceder
            $tieneAcceso = true;
            Log::debug("✅ Usuario tiene acceso CONCEDIDO para módulo: {$key}");
            break;
        case 'D': // Denegar
            $tieneAcceso = false;
            Log::debug("❌ Usuario tiene acceso DENEGADO para módulo: {$key}");
            break;
        default:
            $tieneAcceso = true; // Por defecto, hereda del perfil
            Log::debug("✅ Usuario hereda acceso por defecto para módulo: {$key}");
    }
    
    return $tieneAcceso;
}
    private function obtenerBotonesConPermisosEfectivos($usuarioId, $perfilId, $permiso, $tipoModulo)
    {
        // Obtener botones base según tipo de módulo
        $botonesQuery = null;

        switch ($tipoModulo) {
            case 'opcion':
                $botonesQuery = DB::table('tbl_bot as b')
                    ->join('tbl_opc_bot as ob', 'b.bot_id', '=', 'ob.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->where('ob.opc_id', $permiso->opc_id)
                    ->where('ob.opc_bot_activo', true)
                    ->where('b.bot_activo', true)
                    ->select(
                        'b.bot_id', 'b.bot_nom', 'b.bot_codigo', 'b.bot_color',
                        'b.bot_tooltip', 'b.bot_confirmacion', 'i.ico_nom as ico_nombre',
                        'ob.opc_bot_orden as orden'
                    )
                    ->orderBy('ob.opc_bot_orden');
                break;

            case 'submenu':
                $botonesQuery = DB::table('tbl_bot as b')
                    ->join('tbl_sub_bot as sb', 'b.bot_id', '=', 'sb.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->where('sb.sub_id', $permiso->sub_id)
                    ->where('sb.sub_bot_activo', true)
                    ->where('b.bot_activo', true)
                    ->select(
                        'b.bot_id', 'b.bot_nom', 'b.bot_codigo', 'b.bot_color',
                        'b.bot_tooltip', 'b.bot_confirmacion', 'i.ico_nom as ico_nombre',
                        'sb.sub_bot_orden as orden'
                    )
                    ->orderBy('sb.sub_bot_orden');
                break;

            case 'menu':
                $botonesQuery = DB::table('tbl_bot as b')
                    ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->where('mb.men_id', $permiso->men_id)
                    ->where('mb.men_bot_activo', true)
                    ->where('b.bot_activo', true)
                    ->select(
                        'b.bot_id', 'b.bot_nom', 'b.bot_codigo', 'b.bot_color',
                        'b.bot_tooltip', 'b.bot_confirmacion', 'i.ico_nom as ico_nombre',
                        'mb.men_bot_orden as orden'
                    )
                    ->orderBy('mb.men_bot_orden');
                break;

            default:
                return [];
        }

        if (!$botonesQuery) {
            return [];
        }

        $botonesCollection = $botonesQuery->get();
        $botonesArray = [];

        // ✅ CORRECCIÓN: Evaluar permisos efectivos para cada botón
        foreach ($botonesCollection as $boton) {
            $permisosEfectivos = $this->evaluateEffectiveButtonPermissions(
                $usuarioId,
                $perfilId,
                $permiso->men_id,
                $permiso->sub_id,
                $permiso->opc_id,
                $boton->bot_id
            );

            $botonArray = [
                'bot_id' => $boton->bot_id,
                'bot_nom' => $boton->bot_nom,
                'bot_codigo' => $boton->bot_codigo,
                'bot_color' => $boton->bot_color,
                'bot_tooltip' => $boton->bot_tooltip,
                'bot_confirmacion' => (bool) $boton->bot_confirmacion,
                'ico_nombre' => $boton->ico_nombre,
                'has_permission' => $permisosEfectivos['has_permission'],
                'profile_permission' => $permisosEfectivos['profile_permission'],
                'is_customized' => $permisosEfectivos['is_customized'],
                'customization_type' => $permisosEfectivos['customization_type'],
                'customization_notes' => $permisosEfectivos['customization_notes']
            ];

            $botonesArray[] = $botonArray;
        }

        return $botonesArray;
    }
    private function evaluateEffectiveButtonPermissions($usuarioId, $perfilId, $menId, $subId, $opcId, $botId)
    {
        // 1. Obtener permiso del perfil
        $permisoDelPerfil = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('bot_id', $botId)
            ->where('perm_bot_per_activo', true)
            ->exists();

        // 2. Obtener personalización del usuario
        $personalizacionUsuario = DB::table('tbl_perm_bot_usuario')
            ->where('usu_id', $usuarioId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('bot_id', $botId)
            ->where('perm_bot_usu_activo', true)
            ->first();

        // 3. Evaluar permiso efectivo
        $result = [
            'profile_permission' => $permisoDelPerfil,
            'is_customized' => false,
            'customization_type' => null,
            'customization_notes' => null,
            'has_permission' => $permisoDelPerfil // Por defecto, hereda del perfil
        ];

        // 4. Si hay personalización, aplicarla
        if ($personalizacionUsuario) {
            $result['is_customized'] = true;
            $result['customization_type'] = $personalizacionUsuario->perm_tipo;
            $result['customization_notes'] = $personalizacionUsuario->perm_bot_usu_observaciones;
            
            // ✅ LÓGICA CORREGIDA: La personalización sobrescribe el perfil
            switch ($personalizacionUsuario->perm_tipo) {
                case 'C': // Conceder
                    $result['has_permission'] = true;
                    break;
                case 'D': // Denegar
                    $result['has_permission'] = false;
                    break;
                default:
                    $result['has_permission'] = $permisoDelPerfil;
            }
        }

        return $result;
    }
    public function getUserEffectiveButtonPermissions($usuarioId, $opcId)
    {
        try {
            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
            
            if (!$usuario || !$usuario->per_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no válido o sin perfil asignado'
                ], 404);
            }

            // Obtener información del módulo (opción)
            $opcion = DB::table('tbl_opc as o')
                ->join('tbl_sub_opc as so', 'o.opc_id', '=', 'so.opc_id')
                ->join('tbl_sub as s', 'so.sub_id', '=', 's.sub_id')
                ->join('tbl_men_sub as ms', 's.sub_id', '=', 'ms.sub_id')
                ->join('tbl_men as m', 'ms.men_id', '=', 'm.men_id')
                ->where('o.opc_id', $opcId)
                ->select('m.men_id', 's.sub_id', 'o.opc_id')
                ->first();

            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

            // Verificar si el usuario tiene acceso al módulo
            $tieneAccesoModulo = $this->userHasModuleAccess($usuarioId, $opcion->men_id, $opcion->sub_id, $opcId);
            
            if (!$tieneAccesoModulo) {
                return response()->json([
                    'status' => 'success',
                    'data' => [], // Sin permisos
                    'message' => 'Usuario no tiene acceso a este módulo'
                ]);
            }

            // Obtener botones de la opción con permisos efectivos
            $botones = DB::table('tbl_bot as b')
                ->join('tbl_opc_bot as ob', 'b.bot_id', '=', 'ob.bot_id')
                ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                ->where('ob.opc_id', $opcId)
                ->where('ob.opc_bot_activo', true)
                ->where('b.bot_activo', true)
                ->select(
                    'b.bot_id', 'b.bot_nom', 'b.bot_codigo', 'b.bot_color',
                    'b.bot_tooltip', 'b.bot_confirmacion', 'i.ico_nom as ico_nombre',
                    'ob.opc_bot_orden'
                )
                ->orderBy('ob.opc_bot_orden')
                ->get();

            $botonesConPermisos = [];

            foreach ($botones as $boton) {
                $permisosEfectivos = $this->evaluateEffectiveButtonPermissions(
                    $usuarioId,
                    $usuario->per_id,
                    $opcion->men_id,
                    $opcion->sub_id,
                    $opcId,
                    $boton->bot_id
                );

                $botonesConPermisos[] = [
                    'bot_id' => $boton->bot_id,
                    'bot_nom' => $boton->bot_nom,
                    'bot_codigo' => $boton->bot_codigo,
                    'bot_color' => $boton->bot_color,
                    'bot_tooltip' => $boton->bot_tooltip,
                    'bot_confirmacion' => (bool) $boton->bot_confirmacion,
                    'ico_nombre' => $boton->ico_nombre,
                    'has_permission' => $permisosEfectivos['has_permission'],
                    'is_customized' => $permisosEfectivos['is_customized'],
                    'customization_type' => $permisosEfectivos['customization_type']
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => $botonesConPermisos,
                'user_info' => [
                    'usu_id' => $usuario->usu_id,
                    'per_id' => $usuario->per_id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error obteniendo permisos efectivos del usuario {$usuarioId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos efectivos'
            ], 500);
        }
    }
    /**
     * Remover personalización de usuario (volver a herencia del perfil)
     */
    public function removeUserCustomization(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'usu_id' => 'required|integer|exists:tbl_usu,usu_id',
            'men_id' => 'required|integer|exists:tbl_men,men_id',
            'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'bot_id' => 'required|integer|exists:tbl_bot,bot_id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $deleted = DB::table('tbl_perm_bot_usuario')
                ->where('usu_id', $request->input('usu_id'))
                ->where('men_id', $request->input('men_id'))
                ->where('sub_id', $request->input('sub_id'))
                ->where('opc_id', $request->input('opc_id'))
                ->where('bot_id', $request->input('bot_id'))
                ->delete();

            return response()->json([
                'status' => 'success',
                'message' => $deleted > 0 ? 
                    'Personalización eliminada. El usuario volverá a heredar el permiso del perfil' :
                    'No se encontró personalización para eliminar',
                'removed' => $deleted > 0
            ]);

        } catch (\Exception $e) {
            Log::error("Error removiendo personalización: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar personalización: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Resetear todas las personalizaciones de un usuario
     */
    public function resetUserCustomizations($usuarioId)
    {
        try {
            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $deleted = DB::table('tbl_perm_bot_usuario')
                ->where('usu_id', $usuarioId)
                ->delete();

            return response()->json([
                'status' => 'success',
                'message' => "Se eliminaron {$deleted} personalizaciones. El usuario ahora hereda completamente los permisos de su perfil",
                'customizations_removed' => $deleted
            ]);

        } catch (\Exception $e) {
            Log::error("Error reseteando personalizaciones del usuario {$usuarioId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al resetear personalizaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    public function copyUserCustomizations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_user_id' => 'required|integer|exists:tbl_usu,usu_id',
            'target_user_id' => 'required|integer|exists:tbl_usu,usu_id',
            'overwrite' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $sourceUserId = $request->input('source_user_id');
            $targetUserId = $request->input('target_user_id');
            $overwrite = $request->input('overwrite', false);

            if ($sourceUserId === $targetUserId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario origen y destino no pueden ser el mismo'
                ], 422);
            }

            // Verificar que ambos usuarios tengan el mismo perfil
            $users = DB::table('tbl_usu')
                ->whereIn('usu_id', [$sourceUserId, $targetUserId])
                ->get()
                ->keyBy('usu_id');

            if ($users->count() !== 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Uno o ambos usuarios no existen'
                ], 404);
            }

            $sourceUser = $users[$sourceUserId];
            $targetUser = $users[$targetUserId];

            if ($sourceUser->per_id !== $targetUser->per_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden copiar personalizaciones entre usuarios del mismo perfil'
                ], 422);
            }

            // Si overwrite=true, limpiar personalizaciones existentes del usuario destino
            if ($overwrite) {
                DB::table('tbl_perm_bot_usuario')
                    ->where('usu_id', $targetUserId)
                    ->delete();
            }

            // Obtener personalizaciones del usuario origen
            $sourceCustomizations = DB::table('tbl_perm_bot_usuario')
                ->where('usu_id', $sourceUserId)
                ->where('perm_bot_usu_activo', true)
                ->get();

            $copiedCount = 0;

            foreach ($sourceCustomizations as $customization) {
                $newCustomization = [
                    'usu_id' => $targetUserId,
                    'men_id' => $customization->men_id,
                    'sub_id' => $customization->sub_id,
                    'opc_id' => $customization->opc_id,
                    'bot_id' => $customization->bot_id,
                    'perm_tipo' => $customization->perm_tipo,
                    'perm_bot_usu_observaciones' => 'Copiado del usuario ' . $sourceUserId,
                    'perm_bot_usu_activo' => true,
                    'perm_bot_usu_cre' => now(),
                    'perm_bot_usu_edi' => now(),
                    'perm_bot_usu_creado_por' => Auth::id()
                ];

                // Verificar si ya existe (solo si no overwrite)
                if (!$overwrite) {
                    $exists = DB::table('tbl_perm_bot_usuario')
                        ->where('usu_id', $targetUserId)
                        ->where('men_id', $customization->men_id)
                        ->where('sub_id', $customization->sub_id)
                        ->where('opc_id', $customization->opc_id)
                        ->where('bot_id', $customization->bot_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }
                }

                DB::table('tbl_perm_bot_usuario')->insert($newCustomization);
                $copiedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se copiaron {$copiedCount} personalizaciones correctamente",
                'data' => [
                    'source_user_id' => $sourceUserId,
                    'target_user_id' => $targetUserId,
                    'customizations_copied' => $copiedCount,
                    'overwrite_mode' => $overwrite
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error copiando personalizaciones: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar personalizaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============== MÉTODOS AUXILIARES ==============

    /**
     * Obtener estructura de ventanas directas con botones para un perfil
     */
    private function getDirectWindowsWithButtonsForProfile($perfilId)
    {
        Log::info("🔍 UserController: Obteniendo ventanas directas para perfil: {$perfilId}");

        // 1. Obtener permisos básicos del perfil
        $permisosDelPerfil = DB::table('tbl_perm_perfil')
            ->where('per_id', $perfilId)
            ->where('perm_per_activo', true)
            ->get();

        if ($permisosDelPerfil->isEmpty()) {
            Log::warning("⚠️ Perfil {$perfilId} no tiene permisos básicos asignados");
            return [];
        }

        Log::info("📋 Permisos básicos encontrados: " . $permisosDelPerfil->count());

        $menuStructure = [];

        // ===== PROCESAR CADA PERMISO BÁSICO =====
        foreach ($permisosDelPerfil as $permiso) {
            // Determinar el tipo de módulo y si es ventana directa
            $tipoModulo = $this->determinarTipoModuloUser($permiso);
            
            if (!$tipoModulo['es_ventana_directa']) {
                continue; // Saltar si no es ventana directa
            }

            Log::info("✅ Procesando {$tipoModulo['tipo']}: Men:{$permiso->men_id}, Sub:{$permiso->sub_id}, Opc:{$permiso->opc_id}");

            // Obtener botones según el tipo de módulo
            $botones = $this->obtenerBotonesParaModuloUser($perfilId, $permiso, $tipoModulo['tipo']);

            if (empty($botones)) {
                Log::info("⚠️ No hay botones para este módulo, saltando...");
                continue; // Solo agregar módulos que tengan botones
            }

            // Agregar a la estructura jerárquica
            $this->agregarModuloAEstructuraUser($menuStructure, $permiso, $botones, $tipoModulo['tipo']);
        }

        Log::info("✅ Estructura final UserController: " . count($menuStructure) . " menús con ventanas directas");
        return $menuStructure;
    }
    private function determinarTipoModuloUser($permiso)
    {
        if ($permiso->opc_id) {
            $opcion = DB::table('tbl_opc')->where('opc_id', $permiso->opc_id)->first();
            return [
                'tipo' => 'opcion',
                'es_ventana_directa' => $opcion && $opcion->opc_ventana_directa,
                'info' => $opcion
            ];
        } elseif ($permiso->sub_id) {
            $submenu = DB::table('tbl_sub')->where('sub_id', $permiso->sub_id)->first();
            return [
                'tipo' => 'submenu',
                'es_ventana_directa' => $submenu && $submenu->sub_ventana_directa,
                'info' => $submenu
            ];
        } else {
            $menu = DB::table('tbl_men')->where('men_id', $permiso->men_id)->first();
            return [
                'tipo' => 'menu',
                'es_ventana_directa' => $menu && $menu->men_ventana_directa,
                'info' => $menu
            ];
        }
    }

    /**
     * Obtener botones para un módulo específico según su tipo (UserController)
     */
    private function obtenerBotonesParaModuloUser($perfilId, $permiso, $tipoModulo)
    {
        $botonesQuery = null;

        switch ($tipoModulo) {
            case 'opcion':
                // Botones de una opción (tabla tbl_opc_bot)
                $botonesQuery = DB::table('tbl_bot as b')
                    ->join('tbl_opc_bot as ob', 'b.bot_id', '=', 'ob.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->where('ob.opc_id', $permiso->opc_id)
                    ->where('ob.opc_bot_activo', true)
                    ->where('b.bot_activo', true)
                    ->select(
                        'b.bot_id',
                        'b.bot_nom',
                        'b.bot_codigo',
                        'b.bot_color',
                        'b.bot_tooltip',
                        'b.bot_confirmacion',
                        'i.ico_nom as ico_nombre',
                        'ob.opc_bot_orden as orden'
                    )
                    ->orderBy('ob.opc_bot_orden');
                break;

            case 'submenu':
                // Botones de un submenú (tabla tbl_sub_bot)
                $botonesQuery = DB::table('tbl_bot as b')
                    ->join('tbl_sub_bot as sb', 'b.bot_id', '=', 'sb.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->where('sb.sub_id', $permiso->sub_id)
                    ->where('sb.sub_bot_activo', true)
                    ->where('b.bot_activo', true)
                    ->select(
                        'b.bot_id',
                        'b.bot_nom',
                        'b.bot_codigo',
                        'b.bot_color',
                        'b.bot_tooltip',
                        'b.bot_confirmacion',
                        'i.ico_nom as ico_nombre',
                        'sb.sub_bot_orden as orden'
                    )
                    ->orderBy('sb.sub_bot_orden');
                break;

            case 'menu':
                // Botones de un menú (tabla tbl_men_bot)
                $botonesQuery = DB::table('tbl_bot as b')
                    ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->where('mb.men_id', $permiso->men_id)
                    ->where('mb.men_bot_activo', true)
                    ->where('b.bot_activo', true)
                    ->select(
                        'b.bot_id',
                        'b.bot_nom',
                        'b.bot_codigo',
                        'b.bot_color',
                        'b.bot_tooltip',
                        'b.bot_confirmacion',
                        'i.ico_nom as ico_nombre',
                        'mb.men_bot_orden as orden'
                    )
                    ->orderBy('mb.men_bot_orden');
                break;

            default:
                return []; // Tipo no reconocido
        }

        if (!$botonesQuery) {
            return [];
        }

        // Ejecutar la query y obtener los botones
        $botonesCollection = $botonesQuery->get();
        $botonesArray = [];

        // ✅ CORRECCIÓN: Procesar cada botón correctamente
        foreach ($botonesCollection as $boton) {
            // Verificar permisos del perfil para cada botón
            $tienePermiso = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $permiso->men_id)
                ->where('sub_id', $permiso->sub_id)
                ->where('opc_id', $permiso->opc_id)
                ->where('bot_id', $boton->bot_id)
                ->where('perm_bot_per_activo', true)
                ->exists();

            // Convertir a array para usar en frontend
            $botonArray = [
                'bot_id' => $boton->bot_id,
                'bot_nom' => $boton->bot_nom,
                'bot_codigo' => $boton->bot_codigo,
                'bot_color' => $boton->bot_color,
                'bot_tooltip' => $boton->bot_tooltip,
                'bot_confirmacion' => (bool) $boton->bot_confirmacion,
                'ico_nombre' => $boton->ico_nombre,
                'has_permission' => $tienePermiso, // Permiso del perfil
                'is_customized' => false, // Se marcará después si el usuario tiene personalización
                'profile_permission' => $tienePermiso, // Guardar permiso original del perfil
                'customization_type' => null,
                'customization_notes' => null
            ];

            $botonesArray[] = $botonArray; // ✅ CORRECTO: Agregar al array PHP
        }

        Log::info("🔘 Botones encontrados para {$tipoModulo}: " . count($botonesArray));
        return $botonesArray;
    }

    /**
     * Agregar módulo a la estructura jerárquica (UserController)
     */
    private function agregarModuloAEstructuraUser(&$menuStructure, $permiso, $botones, $tipoModulo)
    {
        // Misma lógica existente
        $menuIndex = $this->buscarOCrearMenuEnEstructuraUser($menuStructure, $permiso->men_id);

        switch ($tipoModulo) {
            case 'menu':
                $menuStructure[$menuIndex]['botones'] = $botones;
                break;
            case 'submenu':
                $submenuIndex = $this->buscarOCrearSubmenuEnEstructuraUser($menuStructure[$menuIndex], $permiso->sub_id);
                $menuStructure[$menuIndex]['submenus'][$submenuIndex]['botones'] = $botones;
                break;
            case 'opcion':
                $submenuIndex = $this->buscarOCrearSubmenuEnEstructuraUser($menuStructure[$menuIndex], $permiso->sub_id);
                $opcionIndex = $this->buscarOCrearOpcionEnEstructuraUser($menuStructure[$menuIndex]['submenus'][$submenuIndex], $permiso->opc_id);
                $menuStructure[$menuIndex]['submenus'][$submenuIndex]['opciones'][$opcionIndex]['botones'] = $botones;
                break;
        }
    }

    /**
     * Aplicar personalizaciones del usuario a la estructura del perfil
     */
    private function applyUserCustomizations(&$menuStructure, $userCustomizations)
    {
        Log::info("🎨 Aplicando " . $userCustomizations->count() . " personalizaciones del usuario");

        foreach ($menuStructure as &$menu) {
            // Botones del menú
            if (isset($menu['botones']) && !empty($menu['botones'])) {
                foreach ($menu['botones'] as &$boton) {
                    $this->applyButtonCustomization(
                        $boton, 
                        $userCustomizations, 
                        $menu['men_id'], 
                        null, 
                        null
                    );
                }
            }

            // Submenús
            if (isset($menu['submenus']) && !empty($menu['submenus'])) {
                foreach ($menu['submenus'] as &$submenu) {
                    // Botones del submenú
                    if (isset($submenu['botones']) && !empty($submenu['botones'])) {
                        foreach ($submenu['botones'] as &$boton) {
                            $this->applyButtonCustomization(
                                $boton, 
                                $userCustomizations, 
                                $menu['men_id'], 
                                $submenu['sub_id'], 
                                null
                            );
                        }
                    }

                    // Opciones
                    if (isset($submenu['opciones']) && !empty($submenu['opciones'])) {
                        foreach ($submenu['opciones'] as &$opcion) {
                            if (isset($opcion['botones']) && !empty($opcion['botones'])) {
                                foreach ($opcion['botones'] as &$boton) {
                                    $this->applyButtonCustomization(
                                        $boton, 
                                        $userCustomizations, 
                                        $menu['men_id'], 
                                        $submenu['sub_id'], 
                                        $opcion['opc_id']
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }

        Log::info("✅ Personalizaciones aplicadas correctamente");
    }
    private function buscarOCrearMenuEnEstructuraUser(&$menuStructure, $menId)
    {
        foreach ($menuStructure as $index => $menu) {
            if ($menu['men_id'] === $menId) {
                return $index;
            }
        }

        $menuInfo = DB::table('tbl_men')
            ->leftJoin('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id')
            ->where('tbl_men.men_id', $menId)
            ->select('tbl_men.*', 'tbl_ico.ico_nom as ico_nombre')
            ->first();

        $nuevoMenu = [
            'men_id' => $menId,
            'men_nom' => $menuInfo->men_nom ?? "Menú {$menId}",
            'men_componente' => $menuInfo->men_componente,
            'men_ventana_directa' => (bool) ($menuInfo->men_ventana_directa ?? false),
            'ico_nombre' => $menuInfo->ico_nombre,
            'botones' => [],
            'submenus' => []
        ];

        $menuStructure[] = $nuevoMenu;
        return count($menuStructure) - 1;
    }
    private function buscarOCrearSubmenuEnEstructuraUser(&$menu, $subId)
    {
        if (!isset($menu['submenus'])) {
            $menu['submenus'] = [];
        }

        foreach ($menu['submenus'] as $index => $submenu) {
            if ($submenu['sub_id'] === $subId) {
                return $index;
            }
        }

        $submenuInfo = DB::table('tbl_sub')
            ->leftJoin('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id')
            ->where('tbl_sub.sub_id', $subId)
            ->select('tbl_sub.*', 'tbl_ico.ico_nom as ico_nombre')
            ->first();

        $nuevoSubmenu = [
            'sub_id' => $subId,
            'sub_nom' => $submenuInfo->sub_nom ?? "Submenú {$subId}",
            'sub_componente' => $submenuInfo->sub_componente,
            'sub_ventana_directa' => (bool) ($submenuInfo->sub_ventana_directa ?? false),
            'ico_nombre' => $submenuInfo->ico_nombre,
            'botones' => [],
            'opciones' => []
        ];

        $menu['submenus'][] = $nuevoSubmenu;
        return count($menu['submenus']) - 1;
    }
    private function buscarOCrearOpcionEnEstructuraUser(&$submenu, $opcId)
    {
        if (!isset($submenu['opciones'])) {
            $submenu['opciones'] = [];
        }

        foreach ($submenu['opciones'] as $index => $opcion) {
            if ($opcion['opc_id'] === $opcId) {
                return $index;
            }
        }

        $opcionInfo = DB::table('tbl_opc')
            ->leftJoin('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id')
            ->where('tbl_opc.opc_id', $opcId)
            ->select('tbl_opc.*', 'tbl_ico.ico_nom as ico_nombre')
            ->first();

        $nuevaOpcion = [
            'opc_id' => $opcId,
            'opc_nom' => $opcionInfo->opc_nom ?? "Opción {$opcId}",
            'opc_componente' => $opcionInfo->opc_componente,
            'opc_ventana_directa' => (bool) ($opcionInfo->opc_ventana_directa ?? false),
            'ico_nombre' => $opcionInfo->ico_nombre,
            'botones' => []
        ];

        $submenu['opciones'][] = $nuevaOpcion;
        return count($submenu['opciones']) - 1;
    }

    /**
     * Aplicar personalización a un botón específico
     */
    private function applyButtonCustomization(&$boton, $userCustomizations, $menId, $subId, $opcId)
    {
        // ✅ CORRECCIÓN: Manejar correctamente los valores NULL
        $subIdForKey = $subId ?? 'null';
        $opcIdForKey = $opcId ?? 'null';
        
        $key = "{$menId}_{$subIdForKey}_{$opcIdForKey}_{$boton['bot_id']}";
        
        Log::debug("🔍 Buscando personalización para clave: {$key}");
        
        if (isset($userCustomizations[$key])) {
            $customization = $userCustomizations[$key];
            
            Log::info("✅ Aplicando personalización: Botón {$boton['bot_id']} -> {$customization->perm_tipo}");
            
            // Guardar el permiso original del perfil
            $boton['profile_permission'] = $boton['has_permission'];
            
            // Aplicar personalización
            $boton['has_permission'] = $customization->perm_tipo === 'C';
            $boton['is_customized'] = true;
            $boton['customization_type'] = $customization->perm_tipo;
            $boton['customization_notes'] = $customization->perm_bot_usu_observaciones;
        } else {
            // Sin personalización, mantener permiso del perfil
            $boton['is_customized'] = false;
            $boton['customization_type'] = null;
            $boton['customization_notes'] = null;
            $boton['profile_permission'] = $boton['has_permission'];
        }
    }

    /**
     * Verificar si el usuario tiene acceso al módulo a través de su perfil
     */
    private function userHasModuleAccess($usuarioId, $menId, $subId, $opcId)
{
    $user = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
    
    if (!$user || !$user->per_id) {
        Log::warning("❌ Usuario {$usuarioId} no tiene perfil asignado");
        return false;
    }

    Log::info("🔍 Verificando acceso: Usuario {$usuarioId}, Perfil {$user->per_id}, Módulo: Men:{$menId}, Sub:{$subId}, Opc:{$opcId}");

    // ✅ CORRECCIÓN: Verificar permisos jerárquicos del perfil
    $hasProfileAccess = DB::table('tbl_perm_perfil')
        ->where('per_id', $user->per_id)
        ->where('men_id', $menId)
        ->where(function ($query) use ($subId, $opcId) {
            if ($opcId) {
                // Para opciones: verificar permiso específico de la opción
                $query->where('sub_id', $subId)
                      ->where('opc_id', $opcId);
            } elseif ($subId) {
                // Para submenús: verificar permiso específico del submenú
                $query->where('sub_id', $subId)
                      ->whereNull('opc_id');
            } else {
                // Para menús: verificar permiso específico del menú
                $query->whereNull('sub_id')
                      ->whereNull('opc_id');
            }
        })
        ->where('perm_per_activo', true)
        ->exists();

    Log::info("🔍 Permiso del perfil: " . ($hasProfileAccess ? 'SÍ' : 'NO'));

    // Si el perfil no tiene permiso, el usuario tampoco puede tener acceso
    if (!$hasProfileAccess) {
        Log::info("❌ Perfil no tiene acceso base al módulo");
        return false;
    }

    // ✅ CORRECCIÓN: Verificar si hay personalización específica del usuario
    $permisoUsuario = DB::table('tbl_perm_usuario')
        ->where('usu_id', $usuarioId)
        ->where('men_id', $menId)
        ->where('sub_id', $subId)
        ->where('opc_id', $opcId)
        ->where('perm_usu_activo', true)
        ->first();

    // Si no hay personalización, hereda del perfil (que ya sabemos que tiene acceso)
    if (!$permisoUsuario) {
        Log::info("✅ Usuario hereda acceso del perfil (sin personalización)");
        return true;
    }

    // Si hay personalización, evaluar según el tipo
    $usuarioTieneAcceso = ($permisoUsuario->perm_tipo === 'C');
    Log::info("🔍 Usuario tiene personalización: " . $permisoUsuario->perm_tipo . " -> " . ($usuarioTieneAcceso ? 'SÍ' : 'NO'));

    return $usuarioTieneAcceso;
}

}
