<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PermissionsController extends Controller
{
    /**
     * Obtener todos los perfiles con información básica
     */
    public function getProfiles()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->select('per_id', 'per_nom')
                ->get();

            // Para cada perfil, contar usuarios
            foreach ($perfiles as $perfil) {
                $usuariosCount = DB::table('tbl_usu')
                    ->where('per_id', $perfil->per_id)
                    ->count();

                $perfil->usuarios_count = $usuariosCount;
            }

            return response()->json([
                'status' => 'success',
                'perfiles' => $perfiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener perfiles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estructura completa de menús con permisos de un perfil
     */
    public function getMenuStructureWithPermissions($perfilId)
    {
        try {
            // Verificar que el perfil existe
            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // Obtener todos los menús con sus íconos
            $menus = DB::table('tbl_men')
                ->join('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_men.men_id',
                    'tbl_men.men_nom',
                    'tbl_men.men_componente',
                    'tbl_men.men_activo', // CORREGIDO: usar men_activo en lugar de men_est
                    'tbl_ico.ico_nom as ico_nombre'
                )
                ->where('tbl_men.men_activo', true) // CORREGIDO
                ->orderBy('tbl_men.men_eje')
                ->get();

            // Para cada menú, obtener submenús y permisos
            foreach ($menus as $menu) {
                // CORREGIDO: Verificar permiso usando tbl_perm_perfil
                $menuPermission = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfilId)
                    ->where('men_id', $menu->men_id)
                    ->whereNull('sub_id')
                    ->whereNull('opc_id')
                    ->where('perm_per_activo', true) // AGREGADO: verificar estado activo
                    ->exists();

                $menu->has_permission = $menuPermission;

                // Obtener submenús del menú
                $submenus = DB::table('tbl_sub')
                    ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                    ->join('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id', 'left')
                    ->select(
                        'tbl_sub.sub_id',
                        'tbl_sub.sub_nom',
                        'tbl_sub.sub_componente',
                        'tbl_sub.sub_activo', // CORREGIDO: usar sub_activo en lugar de sub_est
                        'tbl_ico.ico_nom as ico_nombre'
                    )
                    ->where('tbl_men_sub.men_id', $menu->men_id)
                    ->where('tbl_sub.sub_activo', true) // CORREGIDO
                    ->orderBy('tbl_sub.sub_eje')
                    ->get();

                // Para cada submenú, verificar permisos y obtener opciones
                foreach ($submenus as $submenu) {
                    // CORREGIDO: Verificar permiso usando tbl_perm_perfil
                    $submenuPermission = DB::table('tbl_perm_perfil')
                        ->where('per_id', $perfilId)
                        ->where('men_id', $menu->men_id)
                        ->where('sub_id', $submenu->sub_id)
                        ->whereNull('opc_id')
                        ->where('perm_per_activo', true) // AGREGADO
                        ->exists();

                    $submenu->has_permission = $submenuPermission;

                    // Obtener opciones del submenú
                    $opciones = DB::table('tbl_opc')
                        ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                        ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                        ->select(
                            'tbl_opc.opc_id',
                            'tbl_opc.opc_nom',
                            'tbl_opc.opc_componente',
                            'tbl_opc.opc_ventana_directa',
                            'tbl_opc.opc_activo', // CORREGIDO: usar opc_activo en lugar de opc_est
                            'tbl_ico.ico_nom as ico_nombre'
                        )
                        ->where('tbl_sub_opc.sub_id', $submenu->sub_id)
                        ->where('tbl_opc.opc_activo', true) // CORREGIDO
                        ->orderBy('tbl_opc.opc_eje')
                        ->get();

                    // Para cada opción, verificar permisos
                    foreach ($opciones as $opcion) {
                        // CORREGIDO: Verificar permiso usando tbl_perm_perfil
                        $opcionPermission = DB::table('tbl_perm_perfil')
                            ->where('per_id', $perfilId)
                            ->where('men_id', $menu->men_id)
                            ->where('sub_id', $submenu->sub_id)
                            ->where('opc_id', $opcion->opc_id)
                            ->where('perm_per_activo', true) // AGREGADO
                            ->exists();

                        $opcion->has_permission = $opcionPermission;
                    }

                    $submenu->opciones = $opciones;
                }

                $menu->submenus = $submenus;
            }

            return response()->json([
                'status' => 'success',
                'perfil' => $perfil,
                'menu_structure' => $menus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estructura de menús: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar o revocar permiso específico
     */
    public function togglePermission(Request $request)
{
    $validated = $request->validate([
        'per_id' => 'required|integer|exists:tbl_per,per_id',
        'men_id' => 'required|integer|exists:tbl_men,men_id',
        'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
        'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
        'grant_permission' => 'required|boolean'
    ]);

    try {
        DB::beginTransaction();

        $permissionData = [
            'per_id' => $validated['per_id'],
            'men_id' => $validated['men_id'],
            'sub_id' => $validated['sub_id'],
            'opc_id' => $validated['opc_id']
        ];

        // Verificar si el permiso ya existe
        $existingPermission = DB::table('tbl_perm_perfil')
            ->where($permissionData)
            ->first();

        if ($validated['grant_permission']) {
            // OTORGAR PERMISO
            if (!$existingPermission) {
                // Insertar nuevo permiso
                $permissionData['perm_per_activo'] = true;
                $permissionData['perm_per_cre'] = now();
                $permissionData['perm_per_edi'] = now();
                
                DB::table('tbl_perm_perfil')->insert($permissionData);
                
                // ✅ NUEVO: Auto-asignar botones usando la misma lógica que DirectModulesController
                $this->autoAsignarBotonesBasicos(
                    $validated['per_id'], 
                    $validated['men_id'], 
                    $validated['sub_id'], 
                    $validated['opc_id']
                );
                
                $message = 'Permiso otorgado correctamente con botones básicos asignados';
            } else {
                // Reactivar permiso existente
                DB::table('tbl_perm_perfil')
                    ->where($permissionData)
                    ->update([
                        'perm_per_activo' => true,
                        'perm_per_edi' => now()
                    ]);
                
                // ✅ NUEVO: Verificar y asignar botones si no existen
                $this->verificarYAsignarBotones(
                    $validated['per_id'],
                    $validated['men_id'], 
                    $validated['sub_id'], 
                    $validated['opc_id']
                );
                
                $message = 'Permiso reactivado correctamente';
            }
        } else {
            // REVOCAR PERMISO
            if ($existingPermission) {
                // Desactivar permiso
                DB::table('tbl_perm_perfil')
                    ->where($permissionData)
                    ->update([
                        'perm_per_activo' => false,
                        'perm_per_edi' => now()
                    ]);

                // ✅ NUEVO: Desactivar también los botones asociados
                DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $validated['per_id'])
                    ->where('men_id', $validated['men_id'])
                    ->where('sub_id', $validated['sub_id'])
                    ->where('opc_id', $validated['opc_id'])
                    ->update([
                        'perm_bot_per_activo' => false,
                        'perm_bot_per_edi' => now()
                    ]);

                // Si es un permiso de menú o submenú, también desactivar permisos dependientes
                if (!$validated['sub_id']) {
                    // Si se revoca permiso de menú, desactivar todos los permisos de sus submenús
                    DB::table('tbl_perm_perfil')
                        ->where('per_id', $validated['per_id'])
                        ->where('men_id', $validated['men_id'])
                        ->update([
                            'perm_per_activo' => false,
                            'perm_per_edi' => now()
                        ]);
                        
                    // Y sus botones
                    DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $validated['per_id'])
                        ->where('men_id', $validated['men_id'])
                        ->update([
                            'perm_bot_per_activo' => false,
                            'perm_bot_per_edi' => now()
                        ]);
                        
                } elseif (!$validated['opc_id']) {
                    // Si se revoca permiso de submenú, desactivar todos los permisos de sus opciones
                    DB::table('tbl_perm_perfil')
                        ->where('per_id', $validated['per_id'])
                        ->where('men_id', $validated['men_id'])
                        ->where('sub_id', $validated['sub_id'])
                        ->update([
                            'perm_per_activo' => false,
                            'perm_per_edi' => now()
                        ]);
                        
                    // Y sus botones
                    DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $validated['per_id'])
                        ->where('men_id', $validated['men_id'])
                        ->where('sub_id', $validated['sub_id'])
                        ->update([
                            'perm_bot_per_activo' => false,
                            'perm_bot_per_edi' => now()
                        ]);
                }

                $message = 'Permiso revocado correctamente';
            } else {
                $message = 'El permiso ya estaba revocado';
            }
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => $message
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("Error en togglePermission: " . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Error al modificar permiso: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * NUEVO: Auto-asignar botones básicos CRUD (copiado de DirectModulesController)
 */
private function autoAsignarBotonesBasicos($perfilId, $menId, $subId, $opcId)
{
    try {
        Log::info("🔧 [PermissionsController] Auto-asignando botones básicos para perfil {$perfilId}, menú {$menId}");

        // Determinar tipo de módulo
        $tipo = $this->determinarTipoModulo($menId, $subId, $opcId);
        
        // Solo asignar botones automáticamente si es módulo directo
        if (!$this->esModuloDirecto($menId, $subId, $opcId, $tipo)) {
            Log::info("ℹ️ No es módulo directo, saltando auto-asignación de botones");
            return;
        }

        // Usar la misma lógica que DirectModulesController
        $this->asegurarBotonesAsignados($menId, $subId, $opcId, $tipo);
        
        // Después asegurar permisos de botones
        $this->asegurarPermisosBotones($perfilId, $menId, $subId, $opcId, $tipo);

    } catch (\Exception $e) {
        Log::error("❌ Error auto-asignando botones: " . $e->getMessage());
        // No lanzar excepción para no romper el flujo principal
    }
}

/**
 * NUEVO: Determinar tipo de módulo
 */
private function determinarTipoModulo($menId, $subId, $opcId)
{
    if ($opcId !== null) return 'opcion';
    if ($subId !== null) return 'submenu';
    return 'menu';
}

/**
 * NUEVO: Verificar si es módulo directo
 */
private function esModuloDirecto($menId, $subId, $opcId, $tipo)
{
    switch ($tipo) {
        case 'menu':
            return DB::table('tbl_men')
                ->where('men_id', $menId)
                ->where('men_ventana_directa', true)
                ->exists();
                
        case 'submenu':
            return DB::table('tbl_sub')
                ->where('sub_id', $subId)
                ->where('sub_ventana_directa', true)
                ->exists();
                
        case 'opcion':
            return DB::table('tbl_opc')
                ->where('opc_id', $opcId)
                ->where('opc_ventana_directa', true)
                ->exists();
                
        default:
            return false;
    }
}

/**
 * NUEVO: Asegurar botones asignados (copiado de DirectModulesController)
 */
private function asegurarBotonesAsignados($menId, $subId, $opcId, $tipo)
{
    try {
        switch ($tipo) {
            case 'menu':
                $tabla = 'tbl_men_bot';
                $campoId = 'men_id';
                $valorId = $menId;
                $camposInsercion = [
                    'men_id' => $valorId,
                    'men_bot_orden' => 0,
                    'men_bot_activo' => true,
                    'men_bot_cre' => now(),
                    'men_bot_edi' => now()
                ];
                break;

            case 'submenu':
                $tabla = 'tbl_sub_bot';
                $campoId = 'sub_id';
                $valorId = $subId;
                $camposInsercion = [
                    'sub_id' => $valorId,
                    'sub_bot_orden' => 0,
                    'sub_bot_activo' => true,
                    'sub_bot_cre' => now(),
                    'sub_bot_edi' => now()
                ];
                break;

            case 'opcion':
                $tabla = 'tbl_opc_bot';
                $campoId = 'opc_id';
                $valorId = $opcId;
                $camposInsercion = [
                    'opc_id' => $valorId,
                    'opc_bot_requerido' => false,
                    'opc_bot_orden' => 0,
                    'opc_bot_activo' => true,
                    'opc_bot_cre' => now()
                ];
                break;

            default:
                throw new \Exception("Tipo de módulo no válido: {$tipo}");
        }

        // Verificar si ya tiene botones
        $tieneBotones = DB::table($tabla)->where($campoId, $valorId)->exists();

        if (!$tieneBotones) {
            Log::info("🔧 Asignando botones básicos para {$tipo} ID: {$valorId}");

            // Asignar botones básicos CRUD
            $botones = DB::table('tbl_bot')
                ->where('bot_activo', true)
                ->whereIn('bot_codigo', ['CREATE', 'READ', 'UPDATE', 'DELETE', 'EXPORT'])
                ->orderBy('bot_orden')
                ->get();

            $botonesInsertados = 0;
            foreach ($botones as $index => $boton) {
                try {
                    $datosInsercion = $camposInsercion;
                    $datosInsercion['bot_id'] = $boton->bot_id;

                    // Actualizar orden específico para cada botón
                    if (isset($datosInsercion['men_bot_orden'])) {
                        $datosInsercion['men_bot_orden'] = $index + 1;
                    } elseif (isset($datosInsercion['sub_bot_orden'])) {
                        $datosInsercion['sub_bot_orden'] = $index + 1;
                    } elseif (isset($datosInsercion['opc_bot_orden'])) {
                        $datosInsercion['opc_bot_orden'] = $index + 1;
                    }

                    DB::table($tabla)->insert($datosInsercion);
                    $botonesInsertados++;

                    Log::info("✅ Botón insertado: {$boton->bot_codigo} para {$tipo}");
                } catch (\Exception $e) {
                    Log::error("❌ Error insertando botón {$boton->bot_codigo}: " . $e->getMessage());
                    continue;
                }
            }

            Log::info("✅ Total botones insertados para {$tipo}: {$botonesInsertados}");
        } else {
            Log::info("ℹ️ {$tipo} ID {$valorId} ya tiene botones asignados");
        }
    } catch (\Exception $e) {
        Log::error("❌ Error en asegurarBotonesAsignados para {$tipo}: " . $e->getMessage());
        throw $e;
    }
}

/**
 * NUEVO: Asegurar permisos de botones
 */
private function asegurarPermisosBotones($perfilId, $menId, $subId, $opcId, $tipo)
{
    try {
        // Obtener botones asignados al módulo
        $tabla = $tipo === 'menu' ? 'tbl_men_bot' : ($tipo === 'submenu' ? 'tbl_sub_bot' : 'tbl_opc_bot');
        $campo = $tipo === 'menu' ? 'men_id' : ($tipo === 'submenu' ? 'sub_id' : 'opc_id');
        $valor = $tipo === 'menu' ? $menId : ($tipo === 'submenu' ? $subId : $opcId);

        $botones = DB::table($tabla)
            ->where($campo, $valor)
            ->where(str_replace('tbl_', '', $tabla) . '_activo', true)
            ->get();

        $permisosCreados = 0;
        foreach ($botones as $boton) {
            $existePermiso = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->where('bot_id', $boton->bot_id)
                ->exists();

            if (!$existePermiso) {
                DB::table('tbl_perm_bot_perfil')->insert([
                    'per_id' => $perfilId,
                    'men_id' => $menId,
                    'sub_id' => $subId,
                    'opc_id' => $opcId,
                    'bot_id' => $boton->bot_id,
                    'perm_bot_per_activo' => true,
                    'perm_bot_per_cre' => now(),
                    'perm_bot_per_edi' => now()
                ]);
                $permisosCreados++;
            } else {
                // Reactivar si existe pero está inactivo
                DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilId)
                    ->where('men_id', $menId)
                    ->where('sub_id', $subId)
                    ->where('opc_id', $opcId)
                    ->where('bot_id', $boton->bot_id)
                    ->update([
                        'perm_bot_per_activo' => true,
                        'perm_bot_per_edi' => now()
                    ]);
            }
        }

        Log::info("✅ Permisos de botones asegurados: {$permisosCreados} nuevos");
    } catch (\Exception $e) {
        Log::error("❌ Error asegurando permisos de botones: " . $e->getMessage());
    }
}

/**
 * NUEVO: Verificar y asignar botones si no existen
 */
private function verificarYAsignarBotones($perfilId, $menId, $subId, $opcId)
{
    try {
        // Verificar si ya tiene permisos de botones
        $tienePermisosBotones = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('perm_bot_per_activo', true)
            ->exists();

        if (!$tienePermisosBotones) {
            // Si no tiene permisos de botones, asignarlos
            $this->autoAsignarBotonesBasicos($perfilId, $menId, $subId, $opcId);
        } else {
            // Si ya tiene permisos, solo reactivarlos
            DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->update([
                    'perm_bot_per_activo' => true,
                    'perm_bot_per_edi' => now()
                ]);
                
            Log::info("✅ Permisos de botones reactivados para perfil {$perfilId}");
        }
    } catch (\Exception $e) {
        Log::error("❌ Error verificando botones: " . $e->getMessage());
    }
}
    
    /**
     * Asignación masiva de permisos
     */
    public function bulkAssignPermissions(Request $request)
    {
        $validated = $request->validate([
            'per_id' => 'required|integer|exists:tbl_per,per_id',
            'permissions' => 'required|array',
            'permissions.*.men_id' => 'required|integer|exists:tbl_men,men_id',
            'permissions.*.sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'permissions.*.opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'permissions.*.grant' => 'required|boolean'
        ]);

        try {
            DB::beginTransaction();

            $processedCount = 0;
            $perfilId = $validated['per_id'];

            foreach ($validated['permissions'] as $permission) {
                $permissionData = [
                    'per_id' => $perfilId,
                    'men_id' => $permission['men_id'],
                    'sub_id' => $permission['sub_id'],
                    'opc_id' => $permission['opc_id']
                ];

                // CORREGIDO: Verificar en tbl_perm_perfil
                $existingPermission = DB::table('tbl_perm_perfil')
                    ->where($permissionData)
                    ->first();

                if ($permission['grant'] && !$existingPermission) {
                    // CORREGIDO: Insertar en tbl_perm_perfil
                    $permissionData['perm_per_activo'] = true;
                    $permissionData['perm_per_cre'] = now();
                    $permissionData['perm_per_edi'] = now();
                    
                    DB::table('tbl_perm_perfil')->insert($permissionData);
                    $processedCount++;
                } elseif ($permission['grant'] && $existingPermission && !$existingPermission->perm_per_activo) {
                    // Reactivar permiso existente
                    DB::table('tbl_perm_perfil')
                        ->where($permissionData)
                        ->update([
                            'perm_per_activo' => true,
                            'perm_per_edi' => now()
                        ]);
                    $processedCount++;
                } elseif (!$permission['grant'] && $existingPermission && $existingPermission->perm_per_activo) {
                    // Desactivar permiso
                    DB::table('tbl_perm_perfil')
                        ->where($permissionData)
                        ->update([
                            'perm_per_activo' => false,
                            'perm_per_edi' => now()
                        ]);
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
                'message' => 'Error en asignación masiva: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copiar permisos de un perfil a otro
     */
    public function copyPermissions(Request $request)
    {
        $validated = $request->validate([
            'source_profile_id' => 'required|integer|exists:tbl_per,per_id',
            'target_profile_id' => 'required|integer|exists:tbl_per,per_id',
            'overwrite' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $sourceId = $validated['source_profile_id'];
            $targetId = $validated['target_profile_id'];
            $overwrite = $validated['overwrite'] ?? false;

            if ($sourceId === $targetId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El perfil origen y destino no pueden ser el mismo'
                ], 400);
            }

            // Si se especifica sobrescribir, desactivar permisos existentes del perfil destino
            if ($overwrite) {
                DB::table('tbl_perm_perfil')
                    ->where('per_id', $targetId)
                    ->update([
                        'perm_per_activo' => false,
                        'perm_per_edi' => now()
                    ]);
            }

            // CORREGIDO: Obtener permisos del perfil origen desde tbl_perm_perfil
            $sourcePermissions = DB::table('tbl_perm_perfil')
                ->where('per_id', $sourceId)
                ->where('perm_per_activo', true) // Solo permisos activos
                ->get();

            $copiedCount = 0;

            // Copiar cada permiso
            foreach ($sourcePermissions as $permission) {
                $newPermission = [
                    'per_id' => $targetId,
                    'men_id' => $permission->men_id,
                    'sub_id' => $permission->sub_id,
                    'opc_id' => $permission->opc_id
                ];

                // Verificar si ya existe (solo si no se sobrescribe)
                if (!$overwrite) {
                    $exists = DB::table('tbl_perm_perfil')
                        ->where($newPermission)
                        ->where('perm_per_activo', true)
                        ->exists();

                    if ($exists) {
                        continue;
                    }
                }

                // CORREGIDO: Insertar en tbl_perm_perfil
                $newPermission['perm_per_activo'] = true;
                $newPermission['perm_per_cre'] = now();
                $newPermission['perm_per_edi'] = now();
                
                DB::table('tbl_perm_perfil')->insert($newPermission);
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

    /**
     * Obtener resumen de permisos por perfil
     */
    public function getPermissionsSummary()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->select('per_id', 'per_nom')
                ->where('per_activo', true) // CORREGIDO: verificar que el perfil esté activo
                ->get();

            foreach ($perfiles as $perfil) {
                // CORREGIDO: Contar permisos por tipo desde tbl_perm_perfil
                $menuPermissions = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfil->per_id)
                    ->where('perm_per_activo', true) // AGREGADO
                    ->whereNull('sub_id')
                    ->whereNull('opc_id')
                    ->count();

                $submenuPermissions = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfil->per_id)
                    ->where('perm_per_activo', true) // AGREGADO
                    ->whereNotNull('sub_id')
                    ->whereNull('opc_id')
                    ->count();

                $optionPermissions = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfil->per_id)
                    ->where('perm_per_activo', true) // AGREGADO
                    ->whereNotNull('opc_id')
                    ->count();

                $perfil->permissions_summary = [
                    'menus' => $menuPermissions,
                    'submenus' => $submenuPermissions,
                    'opciones' => $optionPermissions,
                    'total' => $menuPermissions + $submenuPermissions + $optionPermissions
                ];
            }

            return response()->json([
                'status' => 'success',
                'perfiles' => $perfiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener resumen: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getMenuStructureWithButtonPermissions($perfilId)
    {
        Log::info("🔍 Obteniendo estructura de ventanas directas con botones para perfil: {$perfilId}");

        $menuStructure = [];

        // ===== NIVEL 1: MENÚS DIRECTOS =====
        $menusDirectos = DB::table('tbl_men')
            ->leftJoin('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id')
            ->where('tbl_men.men_ventana_directa', true) // ✅ SOLO VENTANAS DIRECTAS
            ->where('tbl_men.men_activo', true)
            ->select(
                'tbl_men.men_id',
                'tbl_men.men_nom',
                'tbl_men.men_componente',
                'tbl_men.men_ventana_directa',
                'tbl_ico.ico_nom as ico_nombre'
            )
            ->orderBy('tbl_men.men_eje')
            ->get();

        foreach ($menusDirectos as $menu) {
            // Verificar si el perfil tiene permiso al menú
            $tienePermisoMenu = DB::table('tbl_perm_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $menu->men_id)
                ->where('sub_id', null)
                ->where('opc_id', null)
                ->where('perm_per_activo', true)
                ->exists();

            if (!$tienePermisoMenu) {
                continue; // ✅ Solo procesar si tiene permiso
            }

            // Verificar si tiene botones asignados en tbl_perm_bot_perfil
            $tieneBotones = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $menu->men_id)
                ->where('sub_id', null)
                ->where('opc_id', null)
                ->exists(); // ✅ Cualquier registro (activo o inactivo)

            if ($tieneBotones) {
                // Obtener TODOS los botones del sistema para este menú directo
                $botones = DB::table('tbl_bot')
                    ->leftJoin('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id')
                    ->where('tbl_bot.bot_activo', true)
                    ->select(
                        'tbl_bot.bot_id',
                        'tbl_bot.bot_nom',
                        'tbl_bot.bot_codigo',
                        'tbl_bot.bot_color',
                        'tbl_bot.bot_tooltip',
                        'tbl_bot.bot_confirmacion',
                        'tbl_ico.ico_nom as ico_nombre'
                    )
                    ->orderBy('tbl_bot.bot_orden')
                    ->get();

                // Para cada botón, verificar si tiene permiso activo
                foreach ($botones as $boton) {
                    $tienePermiso = DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfilId)
                        ->where('men_id', $menu->men_id)
                        ->where('sub_id', null)
                        ->where('opc_id', null)
                        ->where('bot_id', $boton->bot_id)
                        ->where('perm_bot_per_activo', true) // ✅ Solo activos
                        ->exists();

                    $boton->has_permission = $tienePermiso;
                }

                $menu->botones = $botones;
                $menu->submenus = []; // ✅ Menús directos no tienen submenús

                $menuStructure[] = $menu;
                Log::info("✅ Menú directo agregado: {$menu->men_nom} con " . count($botones) . " botones");
            }
        }

        // ===== NIVEL 2: SUBMENÚS DIRECTOS =====
        $submenusDirectos = DB::table('tbl_sub')
            ->leftJoin('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id')
            ->leftJoin('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->leftJoin('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
            ->where('tbl_sub.sub_ventana_directa', true) // ✅ SOLO VENTANAS DIRECTAS
            ->where('tbl_sub.sub_activo', true)
            ->where('tbl_men.men_activo', true)
            ->select(
                'tbl_sub.sub_id',
                'tbl_sub.sub_nom',
                'tbl_sub.sub_componente',
                'tbl_sub.sub_ventana_directa',
                'tbl_sub.ico_nom as ico_nombre',
                'tbl_men.men_id',
                'tbl_men.men_nom'
            )
            ->orderBy('tbl_men.men_eje')
            ->orderBy('tbl_sub.sub_eje')
            ->get();

        // Agrupar submenús por menú
        $submenusPorMenu = $submenusDirectos->groupBy('men_id');

        foreach ($submenusPorMenu as $menId => $submenus) {
            $menuPadre = $submenus->first();

            // Verificar si el menú padre ya está en la estructura
            $menuExistente = collect($menuStructure)->firstWhere('men_id', $menId);

            if (!$menuExistente) {
                // Crear estructura del menú padre si no existe
                $menuExistente = (object) [
                    'men_id' => $menuPadre->men_id,
                    'men_nom' => $menuPadre->men_nom,
                    'men_componente' => null,
                    'men_ventana_directa' => false,
                    'ico_nombre' => null,
                    'botones' => [],
                    'submenus' => []
                ];
                $menuStructure[] = $menuExistente;
            }

            foreach ($submenus as $submenu) {
                // Verificar permisos jerárquicos del submenú
                $tienePermisoSubmenu = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfilId)
                    ->where('men_id', $submenu->men_id)
                    ->where(function ($query) use ($submenu) {
                        $query->where('sub_id', $submenu->sub_id)
                            ->orWhere('sub_id', null); // Permiso heredado del menú
                    })
                    ->where('opc_id', null)
                    ->where('perm_per_activo', true)
                    ->exists();

                if (!$tienePermisoSubmenu) {
                    continue;
                }

                // Verificar si tiene botones asignados
                $tieneBotones = DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilId)
                    ->where('men_id', $submenu->men_id)
                    ->where('sub_id', $submenu->sub_id)
                    ->where('opc_id', null)
                    ->exists();

                if ($tieneBotones) {
                    // Obtener todos los botones para este submenú directo
                    $botones = DB::table('tbl_bot')
                        ->leftJoin('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id')
                        ->where('tbl_bot.bot_activo', true)
                        ->select(
                            'tbl_bot.bot_id',
                            'tbl_bot.bot_nom',
                            'tbl_bot.bot_codigo',
                            'tbl_bot.bot_color',
                            'tbl_bot.bot_tooltip',
                            'tbl_bot.bot_confirmacion',
                            'tbl_ico.ico_nom as ico_nombre'
                        )
                        ->orderBy('tbl_bot.bot_orden')
                        ->get();

                    // Verificar permisos para cada botón
                    foreach ($botones as $boton) {
                        $tienePermiso = DB::table('tbl_perm_bot_perfil')
                            ->where('per_id', $perfilId)
                            ->where('men_id', $submenu->men_id)
                            ->where('sub_id', $submenu->sub_id)
                            ->where('opc_id', null)
                            ->where('bot_id', $boton->bot_id)
                            ->where('perm_bot_per_activo', true)
                            ->exists();

                        $boton->has_permission = $tienePermiso;
                    }

                    $submenu->botones = $botones;
                    $submenu->opciones = []; // ✅ Submenús directos no tienen opciones

                    $menuExistente->submenus[] = $submenu;
                    Log::info("✅ Submenú directo agregado: {$submenu->sub_nom} con " . count($botones) . " botones");
                }
            }
        }

        // ===== NIVEL 3: OPCIONES DIRECTAS =====
        $opcionesDirectas = DB::table('tbl_opc')
            ->leftJoin('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id')
            ->leftJoin('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
            ->leftJoin('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->leftJoin('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
            ->where('tbl_opc.opc_ventana_directa', true) // ✅ SOLO VENTANAS DIRECTAS
            ->where('tbl_opc.opc_activo', true)
            ->where('tbl_sub.sub_activo', true)
            ->where('tbl_men.men_activo', true)
            ->select(
                'tbl_opc.opc_id',
                'tbl_opc.opc_nom',
                'tbl_opc.opc_componente',
                'tbl_opc.opc_ventana_directa',
                'tbl_opc.ico_nom as ico_nombre',
                'tbl_sub.sub_id',
                'tbl_sub.sub_nom',
                'tbl_men.men_id',
                'tbl_men.men_nom'
            )
            ->orderBy('tbl_men.men_eje')
            ->orderBy('tbl_sub.sub_eje')
            ->orderBy('tbl_opc.opc_eje')
            ->get();

        // Agrupar opciones por menú y submenú
        $opcionesPorMenu = $opcionesDirectas->groupBy('men_id');

        foreach ($opcionesPorMenu as $menId => $opcionesDelMenu) {
            $opcionesPorSubmenu = $opcionesDelMenu->groupBy('sub_id');

            foreach ($opcionesPorSubmenu as $subId => $opciones) {
                $primeraOpcion = $opciones->first();

                // Buscar o crear estructura del menú padre
                $menuExistente = collect($menuStructure)->firstWhere('men_id', $menId);
                if (!$menuExistente) {
                    $menuExistente = (object) [
                        'men_id' => $primeraOpcion->men_id,
                        'men_nom' => $primeraOpcion->men_nom,
                        'men_componente' => null,
                        'men_ventana_directa' => false,
                        'ico_nombre' => null,
                        'botones' => [],
                        'submenus' => []
                    ];
                    $menuStructure[] = $menuExistente;
                }

                // Buscar o crear estructura del submenú padre
                $submenuExistente = collect($menuExistente->submenus)->firstWhere('sub_id', $subId);
                if (!$submenuExistente) {
                    $submenuExistente = (object) [
                        'sub_id' => $primeraOpcion->sub_id,
                        'sub_nom' => $primeraOpcion->sub_nom,
                        'sub_componente' => null,
                        'sub_ventana_directa' => false,
                        'ico_nombre' => null,
                        'botones' => [],
                        'opciones' => []
                    ];
                    $menuExistente->submenus[] = $submenuExistente;
                }

                foreach ($opciones as $opcion) {
                    // Verificar permisos jerárquicos de la opción
                    $tienePermisoOpcion = DB::table('tbl_perm_perfil')
                        ->where('per_id', $perfilId)
                        ->where('men_id', $opcion->men_id)
                        ->where(function ($query) use ($opcion) {
                            $query->where('opc_id', $opcion->opc_id)
                                ->orWhere(function ($q) use ($opcion) {
                                    $q->where('sub_id', $opcion->sub_id)->whereNull('opc_id');
                                })
                                ->orWhere(function ($q) {
                                    $q->whereNull('sub_id')->whereNull('opc_id');
                                });
                        })
                        ->where('perm_per_activo', true)
                        ->exists();

                    if (!$tienePermisoOpcion) {
                        continue;
                    }

                    // Verificar si tiene botones asignados
                    $tieneBotones = DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfilId)
                        ->where('men_id', $opcion->men_id)
                        ->where('sub_id', $opcion->sub_id)
                        ->where('opc_id', $opcion->opc_id)
                        ->exists();

                    if ($tieneBotones) {
                        // Obtener todos los botones para esta opción directa
                        $botones = DB::table('tbl_bot')
                            ->leftJoin('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id')
                            ->where('tbl_bot.bot_activo', true)
                            ->select(
                                'tbl_bot.bot_id',
                                'tbl_bot.bot_nom',
                                'tbl_bot.bot_codigo',
                                'tbl_bot.bot_color',
                                'tbl_bot.bot_tooltip',
                                'tbl_bot.bot_confirmacion',
                                'tbl_ico.ico_nom as ico_nombre'
                            )
                            ->orderBy('tbl_bot.bot_orden')
                            ->get();

                        // Verificar permisos para cada botón
                        foreach ($botones as $boton) {
                            $tienePermiso = DB::table('tbl_perm_bot_perfil')
                                ->where('per_id', $perfilId)
                                ->where('men_id', $opcion->men_id)
                                ->where('sub_id', $opcion->sub_id)
                                ->where('opc_id', $opcion->opc_id)
                                ->where('bot_id', $boton->bot_id)
                                ->where('perm_bot_per_activo', true)
                                ->exists();

                            $boton->has_permission = $tienePermiso;
                        }

                        $opcion->botones = $botones;
                        $submenuExistente->opciones[] = $opcion;
                        Log::info("✅ Opción directa agregada: {$opcion->opc_nom} con " . count($botones) . " botones");
                    }
                }
            }
        }

        Log::info("✅ Estructura final para AsigPerBotWindow: " . count($menuStructure) . " menús con ventanas directas");

        return collect($menuStructure)->values()->toArray();
    }
    public function inicializarModulosDirectos(Request $request, $perfil_id)
    {
        try {
            Log::info("🚀 Inicializando módulos directos para perfil: {$perfil_id}");

            // 1. Verificar que el perfil existe
            $perfil = DB::table('tbl_per')->where('per_id', $perfil_id)->first();
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // 2. Obtener permisos del perfil para módulos directos
            $permisosDelPerfil = DB::table('tbl_perm_perfil')
                ->where('per_id', $perfil_id)
                ->where('perm_per_activo', true)
                ->get();

            if ($permisosDelPerfil->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El perfil no tiene permisos asignados para ningún módulo'
                ], 422);
            }

            DB::beginTransaction();

            $modulosInicializados = 0;
            $modulosYaExistentes = 0;
            $modulosProcesados = [];

            // 3. Obtener todos los botones activos del sistema
            $botonesDisponibles = DB::table('tbl_bot')
                ->where('bot_activo', true)
                ->get();

            if ($botonesDisponibles->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'No hay botones activos en el sistema para asignar'
                ], 422);
            }

            // 4. NIVEL 1: Procesar menús directos
            $menusDirectos = DB::table('tbl_men')
                ->where('men_ventana_directa', true)
                ->where('men_activo', true)
                ->get();

            foreach ($menusDirectos as $menu) {
                // Verificar si el perfil tiene permiso al menú
                $tienePermisoMenu = $permisosDelPerfil->where('men_id', $menu->men_id)
                    ->where('sub_id', null)
                    ->where('opc_id', null)
                    ->isNotEmpty();

                if ($tienePermisoMenu) {
                    $yaInicializado = $this->inicializarBotonesParaModulo(
                        $perfil_id,
                        $menu->men_id,
                        null,
                        null,
                        $botonesDisponibles,
                        'Menú: ' . $menu->men_nom
                    );

                    if ($yaInicializado) {
                        $modulosYaExistentes++;
                    } else {
                        $modulosInicializados++;
                    }

                    $modulosProcesados[] = [
                        'tipo' => 'menu',
                        'nombre' => $menu->men_nom,
                        'ya_existia' => $yaInicializado
                    ];
                }
            }

            // 5. NIVEL 2: Procesar submenús directos
            $submenusDirectos = DB::table('tbl_sub')
                ->leftJoin('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                ->leftJoin('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
                ->where('tbl_sub.sub_ventana_directa', true)
                ->where('tbl_sub.sub_activo', true)
                ->where('tbl_men.men_activo', true)
                ->select('tbl_sub.*', 'tbl_men.men_id')
                ->get();

            foreach ($submenusDirectos as $submenu) {
                // Verificar permisos jerárquicos
                $tienePermisoSubmenu = $permisosDelPerfil->where('men_id', $submenu->men_id)
                    ->where(function ($perm) use ($submenu) {
                        return $perm->sub_id == $submenu->sub_id || $perm->sub_id === null;
                    })
                    ->where('opc_id', null)
                    ->isNotEmpty();

                if ($tienePermisoSubmenu) {
                    $yaInicializado = $this->inicializarBotonesParaModulo(
                        $perfil_id,
                        $submenu->men_id,
                        $submenu->sub_id,
                        null,
                        $botonesDisponibles,
                        'Submenú: ' . $submenu->sub_nom
                    );

                    if ($yaInicializado) {
                        $modulosYaExistentes++;
                    } else {
                        $modulosInicializados++;
                    }

                    $modulosProcesados[] = [
                        'tipo' => 'submenu',
                        'nombre' => $submenu->sub_nom,
                        'ya_existia' => $yaInicializado
                    ];
                }
            }

            // 6. NIVEL 3: Procesar opciones directas
            $opcionesDirectas = DB::table('tbl_opc')
                ->leftJoin('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                ->leftJoin('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
                ->leftJoin('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                ->leftJoin('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
                ->where('tbl_opc.opc_ventana_directa', true)
                ->where('tbl_opc.opc_activo', true)
                ->where('tbl_sub.sub_activo', true)
                ->where('tbl_men.men_activo', true)
                ->select('tbl_opc.*', 'tbl_sub.sub_id', 'tbl_men.men_id')
                ->get();

            foreach ($opcionesDirectas as $opcion) {
                // Verificar permisos jerárquicos
                $tienePermisoOpcion = $permisosDelPerfil->where('men_id', $opcion->men_id)
                    ->where(function ($perm) use ($opcion) {
                        return $perm->opc_id == $opcion->opc_id ||
                            ($perm->sub_id == $opcion->sub_id && $perm->opc_id === null) ||
                            ($perm->sub_id === null && $perm->opc_id === null);
                    })
                    ->isNotEmpty();

                if ($tienePermisoOpcion) {
                    $yaInicializado = $this->inicializarBotonesParaModulo(
                        $perfil_id,
                        $opcion->men_id,
                        $opcion->sub_id,
                        $opcion->opc_id,
                        $botonesDisponibles,
                        'Opción: ' . $opcion->opc_nom
                    );

                    if ($yaInicializado) {
                        $modulosYaExistentes++;
                    } else {
                        $modulosInicializados++;
                    }

                    $modulosProcesados[] = [
                        'tipo' => 'opcion',
                        'nombre' => $opcion->opc_nom,
                        'ya_existia' => $yaInicializado
                    ];
                }
            }

            DB::commit();

            Log::info("✅ Inicialización completada", [
                'perfil_id' => $perfil_id,
                'modulos_inicializados' => $modulosInicializados,
                'modulos_ya_existentes' => $modulosYaExistentes,
                'total_botones' => $botonesDisponibles->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Inicialización completada: {$modulosInicializados} módulos inicializados, {$modulosYaExistentes} ya existían",
                'data' => [
                    'modulos_inicializados' => $modulosInicializados,
                    'modulos_ya_existentes' => $modulosYaExistentes,
                    'total_modulos_procesados' => $modulosInicializados + $modulosYaExistentes,
                    'botones_por_modulo' => $botonesDisponibles->count(),
                    'detalle_modulos' => $modulosProcesados,
                    'perfil' => [
                        'per_id' => $perfil->per_id,
                        'per_nom' => $perfil->per_nom
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error en inicialización de módulos directos: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());

            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor durante la inicialización',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Método auxiliar para inicializar botones de un módulo específico
     */
    private function inicializarBotonesParaModulo($perfilId, $menId, $subId, $opcId, $botones, $nombreModulo)
    {
        // Verificar si ya existen permisos para este módulo
        $permisosExistentes = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->exists();

        if ($permisosExistentes) {
            Log::info("⚠️ Módulo ya tiene permisos: {$nombreModulo}");
            return true; // Ya existía
        }

        // Crear permisos para todos los botones
        $permisosCreados = 0;
        foreach ($botones as $boton) {
            try {
                DB::table('tbl_perm_bot_perfil')->insert([
                    'per_id' => $perfilId,
                    'men_id' => $menId,
                    'sub_id' => $subId,
                    'opc_id' => $opcId,
                    'bot_id' => $boton->bot_id,
                    'perm_bot_per_activo' => true,
                    'perm_bot_per_cre' => now(),
                    'perm_bot_per_edi' => now()
                ]);
                $permisosCreados++;
            } catch (\Exception $e) {
                Log::warning("⚠️ Error creando permiso de botón: {$e->getMessage()}", [
                    'perfil_id' => $perfilId,
                    'boton_id' => $boton->bot_id,
                    'modulo' => $nombreModulo
                ]);
            }
        }

        Log::info("✅ Módulo inicializado: {$nombreModulo} con {$permisosCreados} botones");
        return false; // No existía, se creó nuevo
    }

    /**
     * Obtener permisos de botones para el usuario autenticado en una opción específica
     * Este método será útil para el frontend al cargar un CRUD
     */
    // =====================================================
    // MÉTODOS CORREGIDOS PARA PermissionsController.php
    // Agregar estos métodos al controlador existente
    // =====================================================

    /**
     * Obtener permisos de botones para el usuario autenticado en una opción específica
     * Este método será útil para el frontend al cargar un CRUD
     */
    public function getUserButtonPermissionsForOption(Request $request)
    {
        $validated = $request->validate([
            'opc_id' => 'required|integer|exists:tbl_opc,opc_id'
        ]);

        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $userId = $user->usu_id;

            // Usar la vista optimizada
            $botones = DB::table('vw_permisos_usuario_botones')
                ->where('usu_id', $userId)
                ->where('opc_id', $validated['opc_id'])
                ->where('tiene_permiso', true)
                ->select(
                    'bot_id',
                    'boton_nombre',
                    'bot_codigo',
                    'bot_color',
                    'bot_tooltip',
                    'bot_confirmacion',
                    'bot_mensaje_confirmacion'
                )
                ->orderBy('bot_orden')
                ->get();

            return response()->json([
                'status' => 'success',
                'opc_id' => $validated['opc_id'],
                'botones_permitidos' => $botones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos de botones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar múltiples permisos de botones de una vez
     * Útil para validaciones masivas en el frontend
     */
    public function validateMultipleButtonPermissions(Request $request)
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*.opc_id' => 'required|integer|exists:tbl_opc,opc_id',
            'permissions.*.bot_codigo' => 'required|string|exists:tbl_bot,bot_codigo'
        ]);

        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $userId = $user->usu_id;
            $results = [];

            foreach ($validated['permissions'] as $permission) {
                $tienePermiso = DB::table('vw_permisos_usuario_botones')
                    ->where('usu_id', $userId)
                    ->where('opc_id', $permission['opc_id'])
                    ->where('bot_codigo', $permission['bot_codigo'])
                    ->where('tiene_permiso', true)
                    ->exists();

                $results[] = [
                    'opc_id' => $permission['opc_id'],
                    'bot_codigo' => $permission['bot_codigo'],
                    'has_permission' => $tienePermiso
                ];
            }

            return response()->json([
                'status' => 'success',
                'permissions' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al validar permisos múltiples: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de uso de botones por perfil
     */
    public function getButtonUsageStats()
    {
        try {
            $stats = [];

            // Total de botones por código
            $botonesPorTipo = DB::table('tbl_bot')
                ->where('bot_activo', true)
                ->groupBy('bot_codigo')
                ->select('bot_codigo', DB::raw('count(*) as total'))
                ->pluck('total', 'bot_codigo')
                ->toArray();

            // Botones más utilizados en opciones
            $botonesPopulares = DB::table('tbl_opc_bot')
                ->join('tbl_bot', 'tbl_opc_bot.bot_id', '=', 'tbl_bot.bot_id')
                ->where('tbl_opc_bot.opc_bot_activo', true)
                ->where('tbl_bot.bot_activo', true)
                ->groupBy('tbl_bot.bot_id', 'tbl_bot.bot_nom', 'tbl_bot.bot_codigo')
                ->select(
                    'tbl_bot.bot_id',
                    'tbl_bot.bot_nom',
                    'tbl_bot.bot_codigo',
                    DB::raw('count(*) as uso_count')
                )
                ->orderByDesc('uso_count')
                ->limit(10)
                ->get();

            // Perfiles con más permisos de botones
            $perfilesConMasPermisos = DB::table('tbl_perm_bot_perfil')
                ->join('tbl_per', 'tbl_perm_bot_perfil.per_id', '=', 'tbl_per.per_id')
                ->where('tbl_perm_bot_perfil.perm_bot_per_activo', true)
                ->where('tbl_per.per_activo', true)
                ->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                ->select(
                    'tbl_per.per_id',
                    'tbl_per.per_nom',
                    DB::raw('count(*) as permisos_count')
                )
                ->orderByDesc('permisos_count')
                ->limit(10)
                ->get();

            $stats = [
                'botones_por_tipo' => $botonesPorTipo,
                'botones_populares' => $botonesPopulares,
                'perfiles_con_mas_permisos' => $perfilesConMasPermisos,
                'total_botones_activos' => DB::table('tbl_bot')->where('bot_activo', true)->count(),
                'total_asignaciones_activas' => DB::table('tbl_opc_bot')->where('opc_bot_activo', true)->count(),
                'total_permisos_perfil' => DB::table('tbl_perm_bot_perfil')->where('perm_bot_per_activo', true)->count(),
                'total_permisos_usuario' => DB::table('tbl_perm_bot_usuario')->where('perm_bot_usu_activo', true)->count()
            ];

            return response()->json([
                'status' => 'success',
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }


    public function getModulosDirectosDisponibles($perfil_id)
    {
        try {
            Log::info("🔍 Obteniendo módulos directos para perfil: {$perfil_id}");

            // 1. Verificar que el perfil existe
            $perfil = DB::table('tbl_per')->where('per_id', $perfil_id)->first();
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // 2. Obtener permisos del perfil (jerarquía completa)
            $permisosDelPerfil = DB::table('tbl_perm_perfil')
                ->where('per_id', $perfil_id)
                ->where('perm_per_activo', true)
                ->get();

            Log::info("📋 Permisos del perfil encontrados: " . $permisosDelPerfil->count());

            // 3. Construir estructura jerárquica de módulos directos
            $modulosDirectos = [];

            // 3.1 NIVEL 1: Menús directos
            $menusDirectos = DB::table('tbl_men')
                ->leftJoin('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id')
                ->where('tbl_men.men_ventana_directa', true)
                ->where('tbl_men.men_activo', true)
                ->select(
                    'tbl_men.*',
                    'tbl_ico.ico_nom as ico_nombre'
                )
                ->get();

            foreach ($menusDirectos as $menu) {
                // Verificar si el perfil tiene permiso al menú
                $tienePermisoMenu = $permisosDelPerfil->where('men_id', $menu->men_id)
                    ->where('sub_id', null)
                    ->where('opc_id', null)
                    ->isNotEmpty();

                if ($tienePermisoMenu) {
                    // Verificar si ya tiene acceso a botones del menú
                    $tieneAccesoBotones = DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $menu->men_id)
                        ->where('sub_id', null)
                        ->where('opc_id', null)
                        ->where('perm_bot_per_activo', true)
                        ->exists();

                    $modulosDirectos[] = [
                        'men_id' => $menu->men_id,
                        'sub_id' => null,
                        'opc_id' => null,
                        'men_nom' => $menu->men_nom,
                        'sub_nom' => null,
                        'opc_nom' => null,
                        'men_componente' => $menu->men_componente,
                        'sub_componente' => null,
                        'opc_componente' => null,
                        'men_ventana_directa' => true,
                        'sub_ventana_directa' => null,
                        'opc_ventana_directa' => null,
                        'ico_nombre' => $menu->ico_nombre,
                        'tiene_acceso_permisos' => true,
                        'puede_acceder_botones' => $tieneAccesoBotones,
                        'nivel' => 'menu',
                        'tipo' => 'Menú Directo'
                    ];
                }
            }

            // 3.2 NIVEL 2: Submenús directos
            $submenusDirectos = DB::table('tbl_sub')
                ->leftJoin('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id')
                ->leftJoin('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                ->leftJoin('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
                ->where('tbl_sub.sub_ventana_directa', true)
                ->where('tbl_sub.sub_activo', true)
                ->where('tbl_men.men_activo', true)
                ->select(
                    'tbl_sub.*',
                    'tbl_men.men_id',
                    'tbl_men.men_nom',
                    'tbl_ico.ico_nom as ico_nombre'
                )
                ->get();

            foreach ($submenusDirectos as $submenu) {
                // Verificar permisos jerárquicos (submenu específico o menú padre)
                $tienePermisoSubmenu = $permisosDelPerfil->where('men_id', $submenu->men_id)
                    ->where(function ($perm) use ($submenu) {
                        return $perm->sub_id == $submenu->sub_id || $perm->sub_id === null;
                    })
                    ->where('opc_id', null)
                    ->isNotEmpty();

                if ($tienePermisoSubmenu) {
                    // Verificar acceso a botones del submenú
                    $tieneAccesoBotones = DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $submenu->men_id)
                        ->where('sub_id', $submenu->sub_id)
                        ->where('opc_id', null)
                        ->where('perm_bot_per_activo', true)
                        ->exists();

                    $modulosDirectos[] = [
                        'men_id' => $submenu->men_id,
                        'sub_id' => $submenu->sub_id,
                        'opc_id' => null,
                        'men_nom' => $submenu->men_nom,
                        'sub_nom' => $submenu->sub_nom,
                        'opc_nom' => null,
                        'men_componente' => null,
                        'sub_componente' => $submenu->sub_componente,
                        'opc_componente' => null,
                        'men_ventana_directa' => null,
                        'sub_ventana_directa' => true,
                        'opc_ventana_directa' => null,
                        'ico_nombre' => $submenu->ico_nombre,
                        'tiene_acceso_permisos' => true,
                        'puede_acceder_botones' => $tieneAccesoBotones,
                        'nivel' => 'submenu',
                        'tipo' => 'Submenú Directo'
                    ];
                }
            }

            // 3.3 NIVEL 3: Opciones directas
            $opcionesDirectas = DB::table('tbl_opc')
                ->leftJoin('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id')
                ->leftJoin('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                ->leftJoin('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
                ->leftJoin('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                ->leftJoin('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
                ->where('tbl_opc.opc_ventana_directa', true)
                ->where('tbl_opc.opc_activo', true)
                ->where('tbl_sub.sub_activo', true)
                ->where('tbl_men.men_activo', true)
                ->select(
                    'tbl_opc.*',
                    'tbl_sub.sub_id',
                    'tbl_sub.sub_nom',
                    'tbl_men.men_id',
                    'tbl_men.men_nom',
                    'tbl_ico.ico_nom as ico_nombre'
                )
                ->get();

            foreach ($opcionesDirectas as $opcion) {
                // Verificar permisos jerárquicos (opción específica, submenú padre o menú abuelo)
                $tienePermisoOpcion = $permisosDelPerfil->where('men_id', $opcion->men_id)
                    ->where(function ($perm) use ($opcion) {
                        return $perm->opc_id == $opcion->opc_id ||
                            ($perm->sub_id == $opcion->sub_id && $perm->opc_id === null) ||
                            ($perm->sub_id === null && $perm->opc_id === null);
                    })
                    ->isNotEmpty();

                if ($tienePermisoOpcion) {
                    // Verificar acceso a botones de la opción
                    $tieneAccesoBotones = DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $opcion->men_id)
                        ->where('sub_id', $opcion->sub_id)
                        ->where('opc_id', $opcion->opc_id)
                        ->where('perm_bot_per_activo', true)
                        ->exists();

                    $modulosDirectos[] = [
                        'men_id' => $opcion->men_id,
                        'sub_id' => $opcion->sub_id,
                        'opc_id' => $opcion->opc_id,
                        'men_nom' => $opcion->men_nom,
                        'sub_nom' => $opcion->sub_nom,
                        'opc_nom' => $opcion->opc_nom,
                        'men_componente' => null,
                        'sub_componente' => null,
                        'opc_componente' => $opcion->opc_componente,
                        'men_ventana_directa' => null,
                        'sub_ventana_directa' => null,
                        'opc_ventana_directa' => true,
                        'ico_nombre' => $opcion->ico_nombre,
                        'tiene_acceso_permisos' => true,
                        'puede_acceder_botones' => $tieneAccesoBotones,
                        'nivel' => 'option',
                        'tipo' => 'Opción Directa'
                    ];
                }
            }

            Log::info("✅ Módulos directos encontrados: " . count($modulosDirectos));

            return response()->json([
                'status' => 'success',
                'message' => 'Módulos directos obtenidos correctamente',
                'perfil' => [
                    'per_id' => $perfil->per_id,
                    'per_nom' => $perfil->per_nom
                ],
                'modulos_directos' => $modulosDirectos,
                'estadisticas' => [
                    'total' => count($modulosDirectos),
                    'con_acceso_botones' => count(array_filter($modulosDirectos, fn($m) => $m['puede_acceder_botones'])),
                    'sin_acceso_botones' => count(array_filter($modulosDirectos, fn($m) => !$m['puede_acceder_botones']))
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo módulos directos: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());

            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor al obtener módulos directos',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    public function toggleAccesoBotones(Request $request, $perfil_id)
    {
        try {
            Log::info("🔄 Toggle acceso botones - Perfil: {$perfil_id}", $request->all());

            // 1. Validar datos de entrada
            $validator = Validator::make($request->all(), [
                'men_id' => 'required|integer|exists:tbl_men,men_id',
                'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
                'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
                'grant_access' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $menId = $request->input('men_id');
            $subId = $request->input('sub_id');
            $opcId = $request->input('opc_id');
            $grantAccess = $request->input('grant_access');

            // 2. Verificar que el perfil existe
            $perfil = DB::table('tbl_per')->where('per_id', $perfil_id)->first();
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // 3. Verificar que el perfil tiene permisos al módulo
            $tienePermiso = DB::table('tbl_perm_perfil')
                ->where('per_id', $perfil_id)
                ->where('men_id', $menId)
                ->where(function ($query) use ($subId, $opcId) {
                    if ($opcId) {
                        // Para opciones: verificar permiso específico, del submenú o del menú
                        $query->where(function ($q) use ($subId, $opcId) {
                            $q->where('opc_id', $opcId)
                                ->orWhere(function ($q2) use ($subId) {
                                    $q2->where('sub_id', $subId)->whereNull('opc_id');
                                })
                                ->orWhere(function ($q3) {
                                    $q3->whereNull('sub_id')->whereNull('opc_id');
                                });
                        });
                    } elseif ($subId) {
                        // Para submenús: verificar permiso específico o del menú
                        $query->where(function ($q) use ($subId) {
                            $q->where('sub_id', $subId)
                                ->orWhere(function ($q2) {
                                    $q2->whereNull('sub_id')->whereNull('opc_id');
                                });
                        });
                    } else {
                        // Para menús: verificar permiso específico
                        $query->whereNull('sub_id')->whereNull('opc_id');
                    }
                })
                ->where('perm_per_activo', true)
                ->exists();

            if (!$tienePermiso) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El perfil no tiene permisos para acceder a este módulo'
                ], 403);
            }

            // 4. Verificar que el módulo es ventana directa
            $esVentanaDirecta = false;
            $nombreModulo = '';

            if ($opcId) {
                $opcion = DB::table('tbl_opc')
                    ->leftJoin('tbl_sub', 'tbl_opc.opc_id', '=', DB::raw("(SELECT opc_id FROM tbl_sub_opc WHERE sub_id = {$subId} AND opc_id = tbl_opc.opc_id LIMIT 1)"))
                    ->leftJoin('tbl_men', 'tbl_sub.sub_id', '=', DB::raw("(SELECT sub_id FROM tbl_men_sub WHERE men_id = {$menId} AND sub_id = tbl_sub.sub_id LIMIT 1)"))
                    ->where('tbl_opc.opc_id', $opcId)
                    ->select('tbl_opc.opc_ventana_directa', 'tbl_opc.opc_nom')
                    ->first();

                $esVentanaDirecta = $opcion && $opcion->opc_ventana_directa;
                $nombreModulo = $opcion ? $opcion->opc_nom : '';
            } elseif ($subId) {
                $submenu = DB::table('tbl_sub')->where('sub_id', $subId)->first();
                $esVentanaDirecta = $submenu && $submenu->sub_ventana_directa;
                $nombreModulo = $submenu ? $submenu->sub_nom : '';
            } else {
                $menu = DB::table('tbl_men')->where('men_id', $menId)->first();
                $esVentanaDirecta = $menu && $menu->men_ventana_directa;
                $nombreModulo = $menu ? $menu->men_nom : '';
            }

            if (!$esVentanaDirecta) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se puede gestionar el acceso a botones en módulos con ventana directa'
                ], 422);
            }

            DB::beginTransaction();

            try {
                if ($grantAccess) {
                    // 5. OTORGAR ACCESO: Obtener todos los botones disponibles y crear permisos
                    $botones = DB::table('tbl_bot')->where('bot_activo', true)->get();

                    foreach ($botones as $boton) {
                        // Verificar si ya existe el permiso
                        $existePermiso = DB::table('tbl_perm_bot_perfil')
                            ->where('per_id', $perfil_id)
                            ->where('men_id', $menId)
                            ->where('sub_id', $subId)
                            ->where('opc_id', $opcId)
                            ->where('bot_id', $boton->bot_id)
                            ->exists();

                        if (!$existePermiso) {
                            DB::table('tbl_perm_bot_perfil')->insert([
                                'per_id' => $perfil_id,
                                'men_id' => $menId,
                                'sub_id' => $subId,
                                'opc_id' => $opcId,
                                'bot_id' => $boton->bot_id,
                                'perm_bot_per_activo' => true,
                                'perm_bot_per_cre' => now(),
                                'perm_bot_per_edi' => now()
                            ]);
                        } else {
                            // Reactivar si existe pero está inactivo
                            DB::table('tbl_perm_bot_perfil')
                                ->where('per_id', $perfil_id)
                                ->where('men_id', $menId)
                                ->where('sub_id', $subId)
                                ->where('opc_id', $opcId)
                                ->where('bot_id', $boton->bot_id)
                                ->update([
                                    'perm_bot_per_activo' => true,
                                    'perm_bot_per_edi' => now()
                                ]);
                        }
                    }

                    $accion = 'otorgado';
                    $botonesAfectados = $botones->count();
                } else {
                    // 6. REVOCAR ACCESO: Desactivar todos los permisos de botones
                    $botonesAfectados = DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $menId)
                        ->where('sub_id', $subId)
                        ->where('opc_id', $opcId)
                        ->where('perm_bot_per_activo', true)
                        ->count();

                    DB::table('tbl_perm_bot_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $menId)
                        ->where('sub_id', $subId)
                        ->where('opc_id', $opcId)
                        ->update([
                            'perm_bot_per_activo' => false,
                            'perm_bot_per_edi' => now()
                        ]);

                    $accion = 'revocado';
                }

                DB::commit();

                Log::info("✅ Acceso a botones {$accion} correctamente", [
                    'perfil_id' => $perfil_id,
                    'men_id' => $menId,
                    'sub_id' => $subId,
                    'opc_id' => $opcId,
                    'botones_afectados' => $botonesAfectados
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Acceso a botones {$accion} correctamente para '{$nombreModulo}' ({$botonesAfectados} botones afectados)",
                    'data' => [
                        'accion' => $accion,
                        'modulo' => $nombreModulo,
                        'botones_afectados' => $botonesAfectados,
                        'nuevo_estado' => $grantAccess
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error("❌ Error en toggle acceso botones: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());

            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor al modificar acceso a botones',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function syncButtonPermissionsFromOptions(Request $request)
    {
        $validated = $request->validate([
            'per_id' => 'nullable|integer|exists:tbl_per,per_id', // Si no se especifica, sincroniza todos
            'force_overwrite' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $perfilId = $validated['per_id'] ?? null;
            $forceOverwrite = $validated['force_overwrite'] ?? false;

            // Query base para obtener permisos de opciones
            $permisosOpcionQuery = DB::table('tbl_perm_perfil')
                ->where('perm_per_activo', true)
                ->whereNotNull('opc_id');

            if ($perfilId) {
                $permisosOpcionQuery->where('per_id', $perfilId);
            }

            $permisosOpciones = $permisosOpcionQuery->get();

            $sincronizados = 0;

            foreach ($permisosOpciones as $permisoOpcion) {
                // Obtener todos los botones de esta opción
                $botones = DB::table('tbl_opc_bot')
                    ->where('opc_id', $permisoOpcion->opc_id)
                    ->where('opc_bot_activo', true)
                    ->get();

                foreach ($botones as $boton) {
                    $permisoBotonData = [
                        'per_id' => $permisoOpcion->per_id,
                        'men_id' => $permisoOpcion->men_id,
                        'sub_id' => $permisoOpcion->sub_id,
                        'opc_id' => $permisoOpcion->opc_id,
                        'bot_id' => $boton->bot_id
                    ];

                    $existe = DB::table('tbl_perm_bot_perfil')
                        ->where($permisoBotonData)
                        ->exists();

                    if (!$existe || $forceOverwrite) {
                        if ($existe && $forceOverwrite) {
                            DB::table('tbl_perm_bot_perfil')
                                ->where($permisoBotonData)
                                ->update(['perm_bot_per_activo' => true]);
                        } else {
                            $permisoBotonData['perm_bot_per_activo'] = true;
                            DB::table('tbl_perm_bot_perfil')->insert($permisoBotonData);
                        }
                        $sincronizados++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se sincronizaron {$sincronizados} permisos de botones correctamente",
                'sincronizados' => $sincronizados
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al sincronizar permisos: ' . $e->getMessage()
            ], 500);
        }
    }
    public function diagnosticarPerfilSinModulosDirectos(Request $request, $perfil_id)
    {
        try {
            Log::info("🔍 Diagnosticando perfil sin módulos directos: {$perfil_id}");

            // 1. Verificar que el perfil existe
            $perfil = DB::table('tbl_per')->where('per_id', $perfil_id)->first();
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            $diagnostico = [
                'perfil' => [
                    'per_id' => $perfil->per_id,
                    'per_nom' => $perfil->per_nom,
                    'per_activo' => $perfil->per_activo ?? true
                ],
                'problemas_encontrados' => [],
                'soluciones_sugeridas' => [],
                'estadisticas' => []
            ];

            // 2. Verificar permisos del perfil
            $permisosDelPerfil = DB::table('tbl_perm_perfil')
                ->where('per_id', $perfil_id)
                ->where('perm_per_activo', true)
                ->get();

            $diagnostico['estadisticas']['total_permisos'] = $permisosDelPerfil->count();

            if ($permisosDelPerfil->isEmpty()) {
                $diagnostico['problemas_encontrados'][] = "❌ El perfil no tiene NINGÚN permiso asignado";
                $diagnostico['soluciones_sugeridas'][] = "Asignar permisos básicos al perfil en la sección de Permisos";

                return response()->json([
                    'status' => 'warning',
                    'message' => 'Perfil sin permisos asignados',
                    'diagnostico' => $diagnostico
                ]);
            }

            // 3. Verificar módulos con ventana directa en el sistema
            $menusDirectosSistema = DB::table('tbl_men')
                ->where('men_ventana_directa', true)
                ->where('men_activo', true)
                ->count();

            $submenusDirectosSistema = DB::table('tbl_sub')
                ->where('sub_ventana_directa', true)
                ->where('sub_activo', true)
                ->count();

            $opcionesDirectasSistema = DB::table('tbl_opc')
                ->where('opc_ventana_directa', true)
                ->where('opc_activo', true)
                ->count();

            $diagnostico['estadisticas']['modulos_directos_sistema'] = [
                'menus' => $menusDirectosSistema,
                'submenus' => $submenusDirectosSistema,
                'opciones' => $opcionesDirectasSistema,
                'total' => $menusDirectosSistema + $submenusDirectosSistema + $opcionesDirectasSistema
            ];

            if ($diagnostico['estadisticas']['modulos_directos_sistema']['total'] == 0) {
                $diagnostico['problemas_encontrados'][] = "❌ No hay módulos con ventana directa configurados en el sistema";
                $diagnostico['soluciones_sugeridas'][] = "Configurar algunos módulos como ventana directa en Administración de Módulos";
            }

            // 4. Verificar cuáles módulos el perfil puede acceder
            $modulosConPermiso = [];

            // Menús directos con permiso
            $menusConPermiso = DB::table('tbl_men')
                ->join('tbl_perm_perfil', function ($join) use ($perfil_id) {
                    $join->on('tbl_men.men_id', '=', 'tbl_perm_perfil.men_id')
                        ->where('tbl_perm_perfil.per_id', $perfil_id)
                        ->where('tbl_perm_perfil.perm_per_activo', true)
                        ->whereNull('tbl_perm_perfil.sub_id')
                        ->whereNull('tbl_perm_perfil.opc_id');
                })
                ->where('tbl_men.men_activo', true)
                ->select('tbl_men.men_id', 'tbl_men.men_nom', 'tbl_men.men_ventana_directa')
                ->get();

            foreach ($menusConPermiso as $menu) {
                $modulosConPermiso[] = [
                    'tipo' => 'menu',
                    'id' => $menu->men_id,
                    'nombre' => $menu->men_nom,
                    'es_ventana_directa' => (bool) $menu->men_ventana_directa,
                    'nivel' => 'men_id: ' . $menu->men_id
                ];
            }

            $diagnostico['estadisticas']['modulos_con_permiso'] = [
                'menus_total' => $menusConPermiso->count(),
                'menus_directos' => $menusConPermiso->where('men_ventana_directa', true)->count()
            ];

            // 5. Identificar problemas específicos
            $menusDirectosConPermiso = $menusConPermiso->where('men_ventana_directa', true);

            if ($menusDirectosConPermiso->isEmpty()) {
                if ($menusConPermiso->isNotEmpty()) {
                    $diagnostico['problemas_encontrados'][] = "⚠️ El perfil tiene permisos a menús, pero NINGUNO está configurado como ventana directa";
                    $diagnostico['soluciones_sugeridas'][] = "Configurar algunos de los menús permitidos como 'ventana directa' o asignar permisos a menús que ya sean ventana directa";

                    $diagnostico['modulos_disponibles_para_conversion'] = $menusConPermiso->where('men_ventana_directa', false)->map(function ($menu) {
                        return [
                            'men_id' => $menu->men_id,
                            'men_nom' => $menu->men_nom,
                            'accion_sugerida' => 'Cambiar men_ventana_directa = true'
                        ];
                    })->values()->toArray();
                } else {
                    $diagnostico['problemas_encontrados'][] = "❌ El perfil no tiene permisos a ningún menú";
                    $diagnostico['soluciones_sugeridas'][] = "Asignar permisos de menús al perfil";
                }
            }

            // 6. Verificar si tiene botones configurados (aunque no tenga ventanas directas)
            $permisosBotonesTotales = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfil_id)
                ->count();

            $diagnostico['estadisticas']['permisos_botones'] = $permisosBotonesTotales;

            // 7. Sugerir módulos disponibles para asignar
            $menusDirectosDisponibles = DB::table('tbl_men')
                ->leftJoin('tbl_perm_perfil', function ($join) use ($perfil_id) {
                    $join->on('tbl_men.men_id', '=', 'tbl_perm_perfil.men_id')
                        ->where('tbl_perm_perfil.per_id', $perfil_id)
                        ->whereNull('tbl_perm_perfil.sub_id')
                        ->whereNull('tbl_perm_perfil.opc_id');
                })
                ->where('tbl_men.men_ventana_directa', true)
                ->where('tbl_men.men_activo', true)
                ->whereNull('tbl_perm_perfil.per_id') // No tiene permiso
                ->select('tbl_men.men_id', 'tbl_men.men_nom')
                ->get();

            if ($menusDirectosDisponibles->isNotEmpty()) {
                $diagnostico['soluciones_sugeridas'][] = "Asignar permisos a los siguientes menús directos disponibles";
                $diagnostico['modulos_directos_disponibles'] = $menusDirectosDisponibles->toArray();
            }

            // 8. Estado final
            $status = 'success';
            if (!empty($diagnostico['problemas_encontrados'])) {
                $status = count($diagnostico['problemas_encontrados']) > 1 ? 'error' : 'warning';
            }

            return response()->json([
                'status' => $status,
                'message' => "Diagnóstico completado para perfil: {$perfil->per_nom}",
                'diagnostico' => $diagnostico
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en diagnóstico: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error en diagnóstico: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Solución automática: Asignar permisos básicos a menús directos
     */
    public function asignarPermisosBasicosVentanasDirectas(Request $request, $perfil_id)
    {
        $validated = $request->validate([
            'incluir_menus' => 'boolean',
            'incluir_submenus' => 'boolean',
            'incluir_opciones' => 'boolean',
            'menus_especificos' => 'array',
            'menus_especificos.*' => 'integer|exists:tbl_men,men_id'
        ]);

        try {
            // Verificar perfil
            $perfil = DB::table('tbl_per')->where('per_id', $perfil_id)->first();
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            $permisosCreados = 0;
            $incluirMenus = $validated['incluir_menus'] ?? true;
            $incluirSubmenus = $validated['incluir_submenus'] ?? false;
            $incluirOpciones = $validated['incluir_opciones'] ?? false;
            $menusEspecificos = $validated['menus_especificos'] ?? [];

            // 1. Asignar permisos a menús directos
            if ($incluirMenus) {
                $query = DB::table('tbl_men')
                    ->where('men_ventana_directa', true)
                    ->where('men_activo', true);

                if (!empty($menusEspecificos)) {
                    $query->whereIn('men_id', $menusEspecificos);
                }

                $menusDirectos = $query->get();

                foreach ($menusDirectos as $menu) {
                    // Verificar si ya tiene permiso
                    $existePermiso = DB::table('tbl_perm_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $menu->men_id)
                        ->whereNull('sub_id')
                        ->whereNull('opc_id')
                        ->exists();

                    if (!$existePermiso) {
                        DB::table('tbl_perm_perfil')->insert([
                            'per_id' => $perfil_id,
                            'men_id' => $menu->men_id,
                            'sub_id' => null,
                            'opc_id' => null,
                            'perm_per_activo' => true,
                            'perm_per_cre' => now(),
                            'perm_per_edi' => now()
                        ]);
                        $permisosCreados++;
                        Log::info("✅ Permiso creado para menú: {$menu->men_nom}");
                    }
                }
            }

            // 2. Asignar permisos a submenús directos (si se solicita)
            if ($incluirSubmenus) {
                $submenusDirectos = DB::table('tbl_sub')
                    ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                    ->join('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
                    ->where('tbl_sub.sub_ventana_directa', true)
                    ->where('tbl_sub.sub_activo', true)
                    ->where('tbl_men.men_activo', true)
                    ->select('tbl_sub.sub_id', 'tbl_sub.sub_nom', 'tbl_men.men_id')
                    ->get();

                foreach ($submenusDirectos as $submenu) {
                    $existePermiso = DB::table('tbl_perm_perfil')
                        ->where('per_id', $perfil_id)
                        ->where('men_id', $submenu->men_id)
                        ->where('sub_id', $submenu->sub_id)
                        ->whereNull('opc_id')
                        ->exists();

                    if (!$existePermiso) {
                        DB::table('tbl_perm_perfil')->insert([
                            'per_id' => $perfil_id,
                            'men_id' => $submenu->men_id,
                            'sub_id' => $submenu->sub_id,
                            'opc_id' => null,
                            'perm_per_activo' => true,
                            'perm_per_cre' => now(),
                            'perm_per_edi' => now()
                        ]);
                        $permisosCreados++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Permisos básicos asignados: {$permisosCreados} nuevos permisos creados",
                'data' => [
                    'perfil_id' => $perfil_id,
                    'permisos_creados' => $permisosCreados,
                    'incluyo_menus' => $incluirMenus,
                    'incluyo_submenus' => $incluirSubmenus
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error asignando permisos básicos: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error asignando permisos: ' . $e->getMessage()
            ], 500);
        }
    }
    public function configuracionMasivaBotones(Request $request)
    {
        $validated = $request->validate([
            'accion' => 'required|in:inicializar_todos,sincronizar_todos,limpiar_todos',
            'perfiles_especificos' => 'array',
            'perfiles_especificos.*' => 'integer|exists:tbl_per,per_id',
            'incluir_opciones' => 'boolean',
            'force_overwrite' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $accion = $validated['accion'];
            $perfilesEspecificos = $validated['perfiles_especificos'] ?? [];
            $incluirOpciones = $validated['incluir_opciones'] ?? false;
            $forceOverwrite = $validated['force_overwrite'] ?? false;

            // Obtener perfiles a procesar
            $query = DB::table('tbl_per')->where('per_activo', true);
            if (!empty($perfilesEspecificos)) {
                $query->whereIn('per_id', $perfilesEspecificos);
            }
            $perfiles = $query->get();

            $estadisticas = [
                'perfiles_procesados' => 0,
                'modulos_inicializados' => 0,
                'modulos_ya_existentes' => 0,
                'botones_configurados' => 0,
                'errores' => []
            ];

            foreach ($perfiles as $perfil) {
                try {
                    Log::info("🔄 Procesando perfil: {$perfil->per_nom} (ID: {$perfil->per_id})");

                    switch ($accion) {
                        case 'inicializar_todos':
                            $resultado = $this->inicializarBotonesParaPerfil($perfil->per_id, $incluirOpciones, $forceOverwrite);
                            break;

                        case 'sincronizar_todos':
                            $resultado = $this->sincronizarBotonesParaPerfil($perfil->per_id);
                            break;

                        case 'limpiar_todos':
                            $resultado = $this->limpiarBotonesParaPerfil($perfil->per_id);
                            break;

                        default:
                            continue 2;
                    }

                    $estadisticas['perfiles_procesados']++;
                    $estadisticas['modulos_inicializados'] += $resultado['modulos_nuevos'] ?? 0;
                    $estadisticas['modulos_ya_existentes'] += $resultado['modulos_existentes'] ?? 0;
                    $estadisticas['botones_configurados'] += $resultado['botones_configurados'] ?? 0;
                } catch (\Exception $e) {
                    $estadisticas['errores'][] = "Error en perfil {$perfil->per_nom}: " . $e->getMessage();
                    Log::error("❌ Error procesando perfil {$perfil->per_id}: " . $e->getMessage());
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Configuración masiva completada: {$accion}",
                'estadisticas' => $estadisticas
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error en configuración masiva: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error en configuración masiva: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar: Inicializar botones para un perfil específico
     */
    private function inicializarBotonesParaPerfil($perfilId, $incluirOpciones = false, $forceOverwrite = false)
    {
        $modulosNuevos = 0;
        $modulosExistentes = 0;
        $botonesConfigurados = 0;

        // Obtener permisos del perfil
        $permisosDelPerfil = DB::table('tbl_perm_perfil')
            ->where('per_id', $perfilId)
            ->where('perm_per_activo', true)
            ->get();

        // Obtener todos los botones activos
        $botonesDisponibles = DB::table('tbl_bot')->where('bot_activo', true)->get();

        // 1. Procesar menús directos
        $menusDirectos = DB::table('tbl_men')
            ->where('men_ventana_directa', true)
            ->where('men_activo', true)
            ->get();

        foreach ($menusDirectos as $menu) {
            $tienePermiso = $permisosDelPerfil->where('men_id', $menu->men_id)
                ->where('sub_id', null)
                ->where('opc_id', null)
                ->isNotEmpty();

            if ($tienePermiso) {
                $existeBotones = DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilId)
                    ->where('men_id', $menu->men_id)
                    ->where('sub_id', null)
                    ->where('opc_id', null)
                    ->exists();

                if (!$existeBotones || $forceOverwrite) {
                    if ($existeBotones && $forceOverwrite) {
                        // Limpiar existentes
                        DB::table('tbl_perm_bot_perfil')
                            ->where('per_id', $perfilId)
                            ->where('men_id', $menu->men_id)
                            ->where('sub_id', null)
                            ->where('opc_id', null)
                            ->delete();
                    }

                    // Crear permisos para todos los botones
                    foreach ($botonesDisponibles as $boton) {
                        DB::table('tbl_perm_bot_perfil')->insert([
                            'per_id' => $perfilId,
                            'men_id' => $menu->men_id,
                            'sub_id' => null,
                            'opc_id' => null,
                            'bot_id' => $boton->bot_id,
                            'perm_bot_per_activo' => true,
                            'perm_bot_per_cre' => now(),
                            'perm_bot_per_edi' => now()
                        ]);
                        $botonesConfigurados++;
                    }
                    $modulosNuevos++;
                } else {
                    $modulosExistentes++;
                }
            }
        }

        // 2. Procesar submenús directos (similar)
        // 3. Procesar opciones directas (si incluirOpciones = true)

        return [
            'modulos_nuevos' => $modulosNuevos,
            'modulos_existentes' => $modulosExistentes,
            'botones_configurados' => $botonesConfigurados
        ];
    }

    /**
     * Sincronizar botones existentes
     */
    private function sincronizarBotonesParaPerfil($perfilId)
    {
        // Implementar lógica de sincronización
        return ['modulos_nuevos' => 0, 'modulos_existentes' => 0, 'botones_configurados' => 0];
    }

    /**
     * Limpiar todos los botones de un perfil
     */
    private function limpiarBotonesParaPerfil($perfilId)
    {
        $eliminados = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->delete();

        return ['modulos_nuevos' => 0, 'modulos_existentes' => 0, 'botones_configurados' => -$eliminados];
    }
}
