<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                    'tbl_men.men_est',
                    'tbl_ico.ico_nom as ico_nombre'
                )
                ->where('tbl_men.men_est', true) // Solo menús activos
                ->orderBy('tbl_men.men_eje')
                ->get();

            // Para cada menú, obtener submenús y permisos
            foreach ($menus as $menu) {
                // Verificar si el perfil tiene permiso al menú
                $menuPermission = DB::table('tbl_perm')
                    ->where('per_id', $perfilId)
                    ->where('men_id', $menu->men_id)
                    ->whereNull('sub_id')
                    ->whereNull('opc_id')
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
                        'tbl_sub.sub_est',
                        'tbl_ico.ico_nom as ico_nombre'
                    )
                    ->where('tbl_men_sub.men_id', $menu->men_id)
                    ->where('tbl_sub.sub_est', true) // Solo submenús activos
                    ->orderBy('tbl_sub.sub_eje')
                    ->get();

                // Para cada submenú, verificar permisos y obtener opciones
                foreach ($submenus as $submenu) {
                    // Verificar si el perfil tiene permiso al submenú
                    $submenuPermission = DB::table('tbl_perm')
                        ->where('per_id', $perfilId)
                        ->where('men_id', $menu->men_id)
                        ->where('sub_id', $submenu->sub_id)
                        ->whereNull('opc_id')
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
                            'tbl_opc.opc_est',
                            'tbl_ico.ico_nom as ico_nombre'
                        )
                        ->where('tbl_sub_opc.sub_id', $submenu->sub_id)
                        ->where('tbl_opc.opc_est', true) // Solo opciones activas
                        ->orderBy('tbl_opc.opc_eje')
                        ->get();

                    // Para cada opción, verificar permisos
                    foreach ($opciones as $opcion) {
                        $opcionPermission = DB::table('tbl_perm')
                            ->where('per_id', $perfilId)
                            ->where('men_id', $menu->men_id)
                            ->where('sub_id', $submenu->sub_id)
                            ->where('opc_id', $opcion->opc_id)
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
            $existingPermission = DB::table('tbl_perm')
                ->where($permissionData)
                ->first();

            if ($validated['grant_permission']) {
                // Otorgar permiso
                if (!$existingPermission) {
                    DB::table('tbl_perm')->insert($permissionData);
                    $message = 'Permiso otorgado correctamente';
                } else {
                    $message = 'El permiso ya estaba otorgado';
                }
            } else {
                // Revocar permiso
                if ($existingPermission) {
                    DB::table('tbl_perm')
                        ->where($permissionData)
                        ->delete();
                    
                    // Si es un permiso de menú o submenú, también eliminar permisos dependientes
                    if (!$validated['sub_id']) {
                        // Si se revoca permiso de menú, eliminar todos los permisos de sus submenús
                        DB::table('tbl_perm')
                            ->where('per_id', $validated['per_id'])
                            ->where('men_id', $validated['men_id'])
                            ->delete();
                    } elseif (!$validated['opc_id']) {
                        // Si se revoca permiso de submenú, eliminar todos los permisos de sus opciones
                        DB::table('tbl_perm')
                            ->where('per_id', $validated['per_id'])
                            ->where('men_id', $validated['men_id'])
                            ->where('sub_id', $validated['sub_id'])
                            ->delete();
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
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al modificar permiso: ' . $e->getMessage()
            ], 500);
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

                $existingPermission = DB::table('tbl_perm')
                    ->where($permissionData)
                    ->first();

                if ($permission['grant'] && !$existingPermission) {
                    DB::table('tbl_perm')->insert($permissionData);
                    $processedCount++;
                } elseif (!$permission['grant'] && $existingPermission) {
                    DB::table('tbl_perm')
                        ->where($permissionData)
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

            // Si se especifica sobrescribir, eliminar permisos existentes del perfil destino
            if ($overwrite) {
                DB::table('tbl_perm')
                    ->where('per_id', $targetId)
                    ->delete();
            }

            // Obtener permisos del perfil origen
            $sourcePermissions = DB::table('tbl_perm')
                ->where('per_id', $sourceId)
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
                    $exists = DB::table('tbl_perm')
                        ->where($newPermission)
                        ->exists();
                    
                    if ($exists) {
                        continue;
                    }
                }

                DB::table('tbl_perm')->insert($newPermission);
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
                ->get();

            foreach ($perfiles as $perfil) {
                // Contar permisos por tipo
                $menuPermissions = DB::table('tbl_perm')
                    ->where('per_id', $perfil->per_id)
                    ->whereNull('sub_id')
                    ->whereNull('opc_id')
                    ->count();

                $submenuPermissions = DB::table('tbl_perm')
                    ->where('per_id', $perfil->per_id)
                    ->whereNotNull('sub_id')
                    ->whereNull('opc_id')
                    ->count();

                $optionPermissions = DB::table('tbl_perm')
                    ->where('per_id', $perfil->per_id)
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
}