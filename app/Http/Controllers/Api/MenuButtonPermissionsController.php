<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuButtonPermissionsController extends Controller
{
    /**
     * ✅ NUEVO: Obtener permisos de botones para un submenu específico del usuario actual
     */
    public function getMyMenuButtonPermissions($moduleId)
{
    try {
        $userId = Auth::id();
        
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado',
                'botones_permitidos' => []
            ], 401);
        }

        Log::info("🔍 getMyMenuButtonPermissions: Usuario {$userId} consultando módulo {$moduleId}");

        // PASO 1: Determinar si es un menú o submenú directo
        $moduloInfo = $this->determinarTipoModulo($moduleId);
        
        if (!$moduloInfo) {
            Log::warning("❌ Módulo {$moduleId} no encontrado o no es ventana directa");
            return response()->json([
                'success' => false,
                'message' => 'Módulo no encontrado o no es ventana directa',
                'botones_permitidos' => []
            ], 404);
        }

        Log::info("✅ Módulo identificado", $moduloInfo);

        // PASO 2: Obtener información del usuario y su perfil
        $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();

        if (!$usuario || !$usuario->per_id) {
            Log::warning("❌ Usuario {$userId} sin perfil asignado");
            return response()->json([
                'success' => false,
                'message' => 'Usuario sin perfil asignado',
                'botones_permitidos' => []
            ], 400);
        }

        Log::info("👤 Usuario: ID={$userId}, Perfil={$usuario->per_id}");

        // PASO 3: Verificar acceso al módulo
        $tieneAcceso = $this->verificarAccesoModulo($usuario->per_id, $moduloInfo);

        if (!$tieneAcceso) {
            Log::warning("❌ Usuario {$userId} sin acceso al módulo {$moduleId}");
            return response()->json([
                'success' => false,
                'message' => 'Sin permisos para acceder a este módulo',
                'botones_permitidos' => []
            ], 403);
        }

        Log::info("✅ Usuario tiene acceso al módulo");

        // PASO 4: Obtener botones con permisos (incluyendo usuario)
        $botonesConPermisos = $this->obtenerBotonesConPermisos($usuario->per_id, $moduloInfo, $userId);

        // PASO 5: Filtrar solo botones con permiso y formatear respuesta
        $botonesPermitidos = $botonesConPermisos
            ->where('has_permission', true)
            ->map(function($boton) {
                return [
                    'bot_id' => $boton->bot_id,
                    'bot_nom' => $boton->bot_nom,
                    'bot_codigo' => $boton->bot_codigo,
                    'bot_color' => $boton->bot_color,
                    'bot_tooltip' => $boton->bot_tooltip,
                    'bot_confirmacion' => (bool) $boton->bot_confirmacion,
                    'bot_mensaje_confirmacion' => $boton->bot_mensaje_confirmacion,
                    'ico_nombre' => $boton->ico_nombre,
                    'has_permission' => true,
                    'permission_source' => $boton->customizacion_usuario ? 'usuario' : 'perfil'
                ];
            })
            ->values()
            ->toArray();

        Log::info("✅ Permisos procesados", [
            'total_botones' => $botonesConPermisos->count(),
            'botones_permitidos' => count($botonesPermitidos),
            'tipo_modulo' => $moduloInfo['tipo']
        ]);

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Permisos obtenidos correctamente',
            'botones_permitidos' => $botonesPermitidos,
            'menu_info' => [
                'men_id' => $moduleId,
                'men_nom' => $moduloInfo['nombre'],
                'men_ventana_directa' => true,
                'men_componente' => $moduloInfo['componente']
            ],
            'user_info' => [
                'usu_id' => $userId,
                'per_id' => $usuario->per_id
            ],
            'debug_info' => [
                'total_botones_modulo' => $botonesConPermisos->count(),
                'botones_con_permiso' => count($botonesPermitidos),
                'tipo_modulo' => $moduloInfo['tipo'],
                'modulo_info' => $moduloInfo
            ]
        ]);

    } catch (\Exception $e) {
        Log::error("❌ Error en getMyMenuButtonPermissions", [
            'user_id' => $userId ?? 'unknown',
            'module_id' => $moduleId,
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ]);

        return response()->json([
            'success' => false,
            'status' => 'error',
            'message' => 'Error interno del servidor',
            'error_detail' => config('app.debug') ? $e->getMessage() : 'Error interno',
            'botones_permitidos' => []
        ], 500);
    }
}


    /**
     * ✅ NUEVO: Verificar permiso específico de botón para submenu
     */
    public function checkSubmenuButtonPermission(Request $request)
    {
        try {
            $request->validate([
                'men_id' => 'required|integer',
                'sub_id' => 'required|integer',
                'bot_codigo' => 'required|string'
            ]);

            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $menuId = $request->men_id;
            $submenuId = $request->sub_id;
            $botCodigo = $request->bot_codigo;

            Log::info("🔍 Verificando permiso específico de submenu", [
                'user_id' => $userId,
                'menu_id' => $menuId,
                'submenu_id' => $submenuId,
                'bot_codigo' => $botCodigo
            ]);

            // Obtener información del usuario
            $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
            if (!$usuario || !$usuario->per_id) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Usuario sin perfil asignado'
                ]);
            }

            // Verificar permiso híbrido para submenu
            $hasPermission = DB::table('tbl_bot as b')
                ->join('tbl_sub_bot as sb', 'b.bot_id', '=', 'sb.bot_id')
                ->leftJoin('tbl_perm_bot_perfil as pbp', function($join) use ($usuario, $menuId, $submenuId) {
                    $join->on('b.bot_id', '=', 'pbp.bot_id')
                         ->where('pbp.per_id', '=', $usuario->per_id)
                         ->where('pbp.men_id', '=', $menuId)
                         ->where('pbp.sub_id', '=', $submenuId)
                         ->whereNull('pbp.opc_id')
                         ->where('pbp.perm_bot_per_activo', '=', true);
                })
                ->leftJoin('tbl_perm_bot_usuario as pbu', function($join) use ($userId, $menuId, $submenuId) {
                    $join->on('b.bot_id', '=', 'pbu.bot_id')
                         ->where('pbu.usu_id', '=', $userId)
                         ->where('pbu.men_id', '=', $menuId)
                         ->where('pbu.sub_id', '=', $submenuId)
                         ->whereNull('pbu.opc_id')
                         ->where('pbu.perm_bot_usu_activo', '=', true);
                })
                ->where('b.bot_codigo', $botCodigo)
                ->where('sb.men_id', $menuId)
                ->where('sb.sub_id', $submenuId)
                ->where('b.bot_activo', true)
                ->where('sb.sub_bot_activo', true)
                ->selectRaw("
                    CASE 
                        WHEN pbu.perm_tipo = 'C' THEN true
                        WHEN pbu.perm_tipo = 'D' THEN false
                        WHEN pbp.bot_id IS NOT NULL THEN true
                        ELSE false
                    END as has_permission
                ")
                ->value('has_permission');

            return response()->json([
                'has_permission' => (bool) $hasPermission,
                'men_id' => $menuId,
                'sub_id' => $submenuId,
                'bot_codigo' => $botCodigo,
                'user_id' => $userId,
                'profile_id' => $usuario->per_id
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error verificando permiso de botón de submenu", [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'has_permission' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }
    /**
     * ✅ SOLUCIÓN RÁPIDA: Tratar submenus como menús directos
     * Agregar este método al final de tu clase MenuButtonPermissionsController
     */
    public function getMySubmenuAsMenuPermissions($submenuId)
    {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado',
                    'botones_permitidos' => []
                ], 401);
            }

            Log::info("🔍 getMySubmenuAsMenuPermissions: Usuario {$userId} consultando submenu {$submenuId} como menú");

            // Obtener información del usuario y su perfil
            $usuario = DB::table('tbl_usu')
                ->where('usu_id', $userId)
                ->first();

            if (!$usuario || !$usuario->per_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario sin perfil asignado',
                    'botones_permitidos' => []
                ], 400);
            }

            // Verificar que el submenu existe
            $submenu = DB::table('tbl_sub')
                ->where('sub_id', $submenuId)
                ->where('sub_activo', true)
                ->first();

            if (!$submenu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Submenu no encontrado',
                    'botones_permitidos' => []
                ], 404);
            }

            // ✅ MÉTODO SIMPLIFICADO: Buscar botones directamente por sub_id
            $botonesConPermisos = DB::table('tbl_bot as b')
                ->join('tbl_sub_bot as sb', 'b.bot_id', '=', 'sb.bot_id')
                ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                ->leftJoin('tbl_perm_bot_perfil as pbp', function($join) use ($usuario, $submenuId) {
                    $join->on('b.bot_id', '=', 'pbp.bot_id')
                         ->where('pbp.per_id', '=', $usuario->per_id)
                         ->where('pbp.sub_id', '=', $submenuId)
                         ->whereNull('pbp.opc_id')
                         ->where('pbp.perm_bot_per_activo', '=', true);
                })
                ->leftJoin('tbl_perm_bot_usuario as pbu', function($join) use ($userId, $submenuId) {
                    $join->on('b.bot_id', '=', 'pbu.bot_id')
                         ->where('pbu.usu_id', '=', $userId)
                         ->where('pbu.sub_id', '=', $submenuId)
                         ->whereNull('pbu.opc_id')
                         ->where('pbu.perm_bot_usu_activo', '=', true);
                })
                ->where('sb.sub_id', $submenuId)
                ->where('sb.sub_bot_activo', true)
                ->where('b.bot_activo', true)
                ->select(
                    'b.bot_id',
                    'b.bot_nom',
                    'b.bot_codigo',
                    'b.bot_color',
                    'b.bot_tooltip',
                    'b.bot_confirmacion',
                    'b.bot_mensaje_confirmacion',
                    'i.ico_nom as ico_nombre',
                    'sb.sub_bot_orden',
                    'b.bot_orden',
                    DB::raw("
                        CASE 
                            WHEN pbu.perm_tipo = 'C' THEN true
                            WHEN pbu.perm_tipo = 'D' THEN false
                            WHEN pbp.bot_id IS NOT NULL THEN true
                            ELSE false
                        END as has_permission
                    "),
                    'pbp.bot_id as permiso_perfil',
                    'pbu.perm_tipo as customizacion_usuario'
                )
                ->orderBy('sb.sub_bot_orden')
                ->orderBy('b.bot_orden')
                ->get();

            $botonesPermitidos = $botonesConPermisos
                ->where('has_permission', true)
                ->map(function($boton) {
                    return [
                        'bot_id' => $boton->bot_id,
                        'bot_nom' => $boton->bot_nom,
                        'bot_codigo' => $boton->bot_codigo,
                        'bot_color' => $boton->bot_color,
                        'bot_tooltip' => $boton->bot_tooltip,
                        'bot_confirmacion' => (bool) $boton->bot_confirmacion,
                        'bot_mensaje_confirmacion' => $boton->bot_mensaje_confirmacion,
                        'ico_nombre' => $boton->ico_nombre,
                        'has_permission' => true,
                        'permission_source' => $boton->customizacion_usuario ? 'usuario' : 'perfil'
                    ];
                })
                ->values()
                ->toArray();

            Log::info("✅ Permisos de submenu procesados", [
                'submenu_id' => $submenuId,
                'total_botones' => $botonesConPermisos->count(),
                'botones_permitidos' => count($botonesPermitidos),
                'botones_permitidos_codigos' => collect($botonesPermitidos)->pluck('bot_codigo')->toArray()
            ]);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => 'Permisos obtenidos correctamente',
                'botones_permitidos' => $botonesPermitidos,
                'menu_info' => [
                    'men_id' => $submenuId,
                    'men_nom' => $submenu->sub_nom,
                    'men_ventana_directa' => (bool) $submenu->sub_ventana_directa,
                    'men_componente' => $submenu->sub_componente
                ],
                'user_info' => [
                    'usu_id' => $userId,
                    'per_id' => $usuario->per_id
                ],
                'debug_info' => [
                    'total_botones_submenu' => $botonesConPermisos->count(),
                    'botones_con_permiso' => count($botonesPermitidos),
                    'submenu_tratado_como_menu' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error en getMySubmenuAsMenuPermissions", [
                'user_id' => $userId ?? 'unknown',
                'submenu_id' => $submenuId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Error interno del servidor',
                'error_detail' => config('app.debug') ? $e->getMessage() : 'Error interno',
                'botones_permitidos' => []
            ], 500);
        }
    }


    /**
     * Obtener permisos de botones para un menú específico del usuario actual
     * VERSIÓN ORIGINAL MANTENIDA PARA COMPATIBILIDAD
     */
    
    private function determinarTipoModulo($moduleId)
{
    // ✅ PRIMERO verificar si es un SUBMENÚ directo
    $submenu = DB::table('tbl_sub')
        ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
        ->where('tbl_sub.sub_id', $moduleId)
        ->where('tbl_sub.sub_ventana_directa', true)
        ->where('tbl_sub.sub_activo', true)
        ->select('tbl_sub.*', 'tbl_men_sub.men_id')
        ->first();

    if ($submenu) {
        return [
            'tipo' => 'submenu',
            'men_id' => $submenu->men_id,
            'sub_id' => $submenu->sub_id,
            'opc_id' => null,
            'nombre' => $submenu->sub_nom,
            'componente' => $submenu->sub_componente,
            'tabla_botones' => 'tbl_sub_bot',
            'campo_modulo' => 'sub_id',
            'campo_activo' => 'sub_bot_activo'
        ];
    }

    // DESPUÉS verificar si es un menú directo
    $menu = DB::table('tbl_men')
        ->where('men_id', $moduleId)
        ->where('men_ventana_directa', true)
        ->where('men_activo', true)
        ->first();

    if ($menu) {
        return [
            'tipo' => 'menu',
            'men_id' => $menu->men_id,
            'sub_id' => null,
            'opc_id' => null,
            'nombre' => $menu->men_nom,
            'componente' => $menu->men_componente,
            'tabla_botones' => 'tbl_men_bot',
            'campo_modulo' => 'men_id',
            'campo_activo' => 'men_bot_activo'
        ];
    }

    // Verificar si es una opción directa
    $opcion = DB::table('tbl_opc')
        ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
        ->join('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
        ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
        ->where('tbl_opc.opc_id', $moduleId)
        ->where('tbl_opc.opc_ventana_directa', true)
        ->where('tbl_opc.opc_activo', true)
        ->select('tbl_opc.*', 'tbl_sub.sub_id', 'tbl_men_sub.men_id')
        ->first();

    if ($opcion) {
        return [
            'tipo' => 'opcion',
            'men_id' => $opcion->men_id,
            'sub_id' => $opcion->sub_id,
            'opc_id' => $opcion->opc_id,
            'nombre' => $opcion->opc_nom,
            'componente' => $opcion->opc_componente,
            'tabla_botones' => 'tbl_opc_bot',
            'campo_modulo' => 'opc_id',
            'campo_activo' => 'opc_bot_activo'
        ];
    }

    return null; // No es ventana directa o no existe
}



/**
 * ✅ MÉTODO NUEVO: Verificar acceso a módulo (menú/submenú/opción)
 */
private function verificarAccesoModulo($perfilId, $moduloInfo)
{
    return DB::table('tbl_perm_perfil')
        ->where('per_id', $perfilId)
        ->where('men_id', $moduloInfo['men_id'])
        ->where('sub_id', $moduloInfo['sub_id'])
        ->where('opc_id', $moduloInfo['opc_id'])
        ->where('perm_per_activo', true)
        ->exists();
}

/**
 * ✅ MÉTODO NUEVO: Obtener botones con permisos
 */
private function obtenerBotonesConPermisos($perfilId, $moduloInfo, $usuarioId = null)
{
    return DB::table('tbl_bot as b')
        ->join($moduloInfo['tabla_botones'] . ' as rel', 'b.bot_id', '=', 'rel.bot_id')
        ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
        // ✅ PERMISOS DEL PERFIL
        ->leftJoin('tbl_perm_bot_perfil as pbp', function($join) use ($perfilId, $moduloInfo) {
            $join->on('b.bot_id', '=', 'pbp.bot_id')
                 ->where('pbp.per_id', '=', $perfilId)
                 ->where('pbp.men_id', '=', $moduloInfo['men_id'])
                 ->where('pbp.sub_id', '=', $moduloInfo['sub_id'])
                 ->where('pbp.opc_id', '=', $moduloInfo['opc_id'])
                 ->where('pbp.perm_bot_per_activo', '=', true);
        })
        // ✅ PERMISOS PERSONALIZADOS DEL USUARIO
        ->leftJoin('tbl_perm_bot_usuario as pbu', function($join) use ($usuarioId, $moduloInfo) {
            $join->on('b.bot_id', '=', 'pbu.bot_id');
            if ($usuarioId) {
                $join->where('pbu.usu_id', '=', $usuarioId)
                     ->where('pbu.men_id', '=', $moduloInfo['men_id'])
                     ->where('pbu.sub_id', '=', $moduloInfo['sub_id'])
                     ->where('pbu.opc_id', '=', $moduloInfo['opc_id']);
                     // ✅ Removido: ->where('pbu.perm_bot_usu_activo', '=', true) 
                     // porque no hay campo _activo en esta tabla
            }
        })
        ->where('rel.' . $moduloInfo['campo_modulo'], $moduloInfo[$moduloInfo['campo_modulo']])
        ->where('rel.' . $moduloInfo['campo_activo'], true)
        ->where('b.bot_activo', true)
        ->select(
            'b.bot_id',
            'b.bot_nom',
            'b.bot_codigo',
            'b.bot_color',
            'b.bot_tooltip',
            'b.bot_confirmacion',
            'b.bot_mensaje_confirmacion',
            'i.ico_nom as ico_nombre',
            // ✅ LÓGICA DE PRIORIDAD: Usuario > Perfil (con nombres correctos de columnas)
            DB::raw("CASE 
                WHEN pbu.bot_id IS NOT NULL THEN 
                    CASE WHEN pbu.perm_tipo = 'C' THEN true ELSE false END
                WHEN pbp.bot_id IS NOT NULL THEN true 
                ELSE false 
            END as has_permission"),
            DB::raw("CASE WHEN pbu.bot_id IS NOT NULL THEN true ELSE false END as customizacion_usuario"),
            DB::raw("CASE WHEN pbp.bot_id IS NOT NULL THEN true ELSE false END as permiso_perfil"),
            'pbu.perm_tipo as tipo_personalizacion'
        )
        ->orderBy('rel.' . str_replace('_activo', '_orden', $moduloInfo['campo_activo']))
        ->orderBy('b.bot_orden')
        ->get();
}

    /**
     * Verificar permiso específico de botón para menú directo
     */
    public function checkMenuButtonPermission(Request $request)
    {
        try {
            $request->validate([
                'men_id' => 'required|integer',
                'bot_codigo' => 'required|string'
            ]);

            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $menuId = $request->men_id;
            $botCodigo = $request->bot_codigo;

            Log::info("🔍 Verificando permiso específico", [
                'user_id' => $userId,
                'menu_id' => $menuId,
                'bot_codigo' => $botCodigo
            ]);

            // Obtener información del usuario
            $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
            if (!$usuario || !$usuario->per_id) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Usuario sin perfil asignado'
                ]);
            }

            // Verificar permiso híbrido
            $hasPermission = DB::table('tbl_bot as b')
                ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                ->leftJoin('tbl_perm_bot_perfil as pbp', function($join) use ($usuario, $menuId) {
                    $join->on('b.bot_id', '=', 'pbp.bot_id')
                         ->where('pbp.per_id', '=', $usuario->per_id)
                         ->where('pbp.men_id', '=', $menuId)
                         ->whereNull('pbp.sub_id')
                         ->whereNull('pbp.opc_id')
                         ->where('pbp.perm_bot_per_activo', '=', true);
                })
                ->leftJoin('tbl_perm_bot_usuario as pbu', function($join) use ($userId, $menuId) {
                    $join->on('b.bot_id', '=', 'pbu.bot_id')
                         ->where('pbu.usu_id', '=', $userId)
                         ->where('pbu.men_id', '=', $menuId)
                         ->whereNull('pbu.sub_id')
                         ->whereNull('pbu.opc_id')
                         ->where('pbu.perm_bot_usu_activo', '=', true);
                })
                ->where('b.bot_codigo', $botCodigo)
                ->where('mb.men_id', $menuId)
                ->where('b.bot_activo', true)
                ->where('mb.men_bot_activo', true)
                ->selectRaw("
                    CASE 
                        WHEN pbu.perm_tipo = 'C' THEN true
                        WHEN pbu.perm_tipo = 'D' THEN false
                        WHEN pbp.bot_id IS NOT NULL THEN true
                        ELSE false
                    END as has_permission
                ")
                ->value('has_permission');

            return response()->json([
                'has_permission' => (bool) $hasPermission,
                'men_id' => $menuId,
                'bot_codigo' => $botCodigo,
                'user_id' => $userId,
                'profile_id' => $usuario->per_id
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error verificando permiso de botón", [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'has_permission' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener todos los permisos del usuario actual
     */
    public function getMyPermissions()
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
            if (!$usuario || !$usuario->per_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario sin perfil asignado'
                ]);
            }

            // Obtener todos los permisos de botones del perfil
            $permisos = DB::table('tbl_perm_bot_perfil as pbp')
                ->join('tbl_bot as b', 'pbp.bot_id', '=', 'b.bot_id')
                ->leftJoin('tbl_men as m', 'pbp.men_id', '=', 'm.men_id')
                ->leftJoin('tbl_sub as s', 'pbp.sub_id', '=', 's.sub_id')
                ->leftJoin('tbl_opc as o', 'pbp.opc_id', '=', 'o.opc_id')
                ->where('pbp.per_id', $usuario->per_id)
                ->where('pbp.perm_bot_per_activo', true)
                ->where('b.bot_activo', true)
                ->select(
                    'b.bot_id',
                    'b.bot_codigo',
                    'b.bot_nom',
                    'b.bot_tooltip',
                    'b.bot_color',
                    'm.men_id',
                    'm.men_nom',
                    's.sub_id',
                    's.sub_nom',
                    'o.opc_id',
                    'o.opc_nom'
                )
                ->get()
                ->groupBy(['men_id', 'sub_id', 'opc_id']);

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos correctamente',
                'permissions' => $permisos,
                'user_info' => [
                    'usu_id' => $userId,
                    'per_id' => $usuario->per_id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo permisos del usuario", [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener información de menús directos con botones para un perfil
     */
    public function getMenuButtonInfo($perfilId = null)
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Si no se especifica perfil, usar el del usuario actual
            if (!$perfilId) {
                $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
                $perfilId = $usuario?->per_id;
            }

            if (!$perfilId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo determinar el perfil'
                ], 400);
            }

            // Obtener menús directos con sus botones y permisos
            $menusDirectos = DB::table('tbl_men as m')
                ->leftJoin('tbl_ico as i', 'm.ico_id', '=', 'i.ico_id')
                ->where('m.men_ventana_directa', true)
                ->where('m.men_activo', true)
                ->select('m.*', 'i.ico_nom')
                ->orderBy('m.men_orden')
                ->get()
                ->map(function($menu) use ($perfilId) {
                    // Obtener botones del menú con permisos
                    $botones = DB::table('tbl_bot as b')
                        ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                        ->leftJoin('tbl_perm_bot_perfil as pbp', function($join) use ($perfilId, $menu) {
                            $join->on('b.bot_id', '=', 'pbp.bot_id')
                                 ->where('pbp.per_id', '=', $perfilId)
                                 ->where('pbp.men_id', '=', $menu->men_id)
                                 ->whereNull('pbp.sub_id')
                                 ->whereNull('pbp.opc_id')
                                 ->where('pbp.perm_bot_per_activo', '=', true);
                        })
                        ->where('mb.men_id', $menu->men_id)
                        ->where('mb.men_bot_activo', true)
                        ->where('b.bot_activo', true)
                        ->select(
                            'b.*',
                            'mb.men_bot_orden',
                            DB::raw('CASE WHEN pbp.bot_id IS NOT NULL THEN true ELSE false END as has_permission')
                        )
                        ->orderBy('mb.men_bot_orden')
                        ->get();

                    return [
                        'men_id' => $menu->men_id,
                        'men_nom' => $menu->men_nom,
                        'men_componente' => $menu->men_componente,
                        'ico_nombre' => $menu->ico_nom,
                        'botones' => $botones->map(function($boton) {
                            return [
                                'bot_id' => $boton->bot_id,
                                'bot_codigo' => $boton->bot_codigo,
                                'bot_nom' => $boton->bot_nom,
                                'bot_tooltip' => $boton->bot_tooltip,
                                'bot_color' => $boton->bot_color,
                                'has_permission' => (bool) $boton->has_permission
                            ];
                        }),
                        'total_botones' => $botones->count(),
                        'botones_permitidos' => $botones->where('has_permission', true)->count()
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Información obtenida correctamente',
                'menus_directos' => $menusDirectos,
                'perfil_id' => $perfilId,
                'total_menus' => $menusDirectos->count()
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo info de menús directos", [
                'user_id' => Auth::id(),
                'perfil_id' => $perfilId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }
}


