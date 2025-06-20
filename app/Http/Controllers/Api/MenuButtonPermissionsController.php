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
     * Obtener permisos de botones para un menú específico del usuario actual
     * VERSIÓN CORREGIDA USANDO SOLO QUERY BUILDER
     */
    public function getMyMenuButtonPermissions($menuId)
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

            Log::info("🔍 getMyMenuButtonPermissions: Usuario {$userId} consultando menú {$menuId}");

            // PASO 1: Verificar que el menú existe y está activo
            $menu = DB::table('tbl_men')
                ->where('men_id', $menuId)
                ->where('men_activo', true)
                ->first();

            if (!$menu) {
                Log::warning("❌ Menú {$menuId} no encontrado o inactivo");
                return response()->json([
                    'success' => false,
                    'message' => 'Menú no encontrado',
                    'botones_permitidos' => []
                ], 404);
            }

            Log::info("✅ Menú encontrado: {$menu->men_nom}");

            // PASO 2: Obtener información del usuario y su perfil
            $usuario = DB::table('tbl_usu')
                ->where('usu_id', $userId)
                ->first();

            if (!$usuario || !$usuario->per_id) {
                Log::warning("❌ Usuario {$userId} sin perfil asignado");
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario sin perfil asignado',
                    'botones_permitidos' => []
                ], 400);
            }

            Log::info("👤 Usuario: ID={$userId}, Perfil={$usuario->per_id}");

            // PASO 3: Verificar si el usuario tiene acceso al menú
            $tieneAccesoMenu = DB::table('tbl_perm_perfil')
                ->where('per_id', $usuario->per_id)
                ->where('men_id', $menuId)
                ->whereNull('sub_id')
                ->whereNull('opc_id')
                ->where('perm_per_activo', true)
                ->exists();

            if (!$tieneAccesoMenu) {
                Log::warning("❌ Usuario {$userId} sin acceso al menú {$menuId}");
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permisos para acceder a este menú',
                    'botones_permitidos' => []
                ], 403);
            }

            Log::info("✅ Usuario tiene acceso al menú");

            // PASO 4: Obtener botones asignados al menú si es ventana directa
            if (!$menu->men_ventana_directa) {
                Log::info("ℹ️ Menú no es ventana directa, devolviendo vacío");
                return response()->json([
                    'success' => true,
                    'message' => 'Menú no es ventana directa',
                    'botones_permitidos' => [],
                    'menu_info' => [
                        'men_id' => $menu->men_id,
                        'men_nom' => $menu->men_nom,
                        'men_ventana_directa' => false
                    ]
                ]);
            }

            // PASO 5: Obtener botones del menú con permisos
            $botonesConPermisos = DB::table('tbl_bot as b')
                ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                ->leftJoin('tbl_perm_bot_perfil as pbp', function($join) use ($usuario, $menuId) {
                    $join->on('b.bot_id', '=', 'pbp.bot_id')
                         ->where('pbp.per_id', '=', $usuario->per_id)
                         ->where('pbp.men_id', '=', $menuId)
                         ->whereNull('pbp.sub_id')
                         ->whereNull('pbp.opc_id')
                         ->where('pbp.perm_bot_per_activo', '=', true);
                })
                // Verificar personalizaciones del usuario
                ->leftJoin('tbl_perm_bot_usuario as pbu', function($join) use ($userId, $menuId) {
                    $join->on('b.bot_id', '=', 'pbu.bot_id')
                         ->where('pbu.usu_id', '=', $userId)
                         ->where('pbu.men_id', '=', $menuId)
                         ->whereNull('pbu.sub_id')
                         ->whereNull('pbu.opc_id')
                         ->where('pbu.perm_bot_usu_activo', '=', true);
                })
                ->where('mb.men_id', $menuId)
                ->where('mb.men_bot_activo', true)
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
                    'mb.men_bot_orden',
                    'b.bot_orden',
                    // Calcular permiso final (usuario override perfil)
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
                ->orderBy('mb.men_bot_orden')
                ->orderBy('b.bot_orden')
                ->get();

            Log::info("🔘 Botones encontrados: " . $botonesConPermisos->count());

            // PASO 6: Filtrar solo botones con permiso y formatear respuesta
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
                'botones_permitidos_codigos' => collect($botonesPermitidos)->pluck('bot_codigo')->toArray()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos correctamente',
                'botones_permitidos' => $botonesPermitidos,
                'menu_info' => [
                    'men_id' => $menu->men_id,
                    'men_nom' => $menu->men_nom,
                    'men_ventana_directa' => (bool) $menu->men_ventana_directa,
                    'men_componente' => $menu->men_componente
                ],
                'user_info' => [
                    'usu_id' => $userId,
                    'per_id' => $usuario->per_id
                ],
                'debug_info' => [
                    'total_botones_menu' => $botonesConPermisos->count(),
                    'botones_con_permiso' => count($botonesPermitidos),
                    'tiene_acceso_menu' => $tieneAccesoMenu
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error en getMyMenuButtonPermissions", [
                'user_id' => $userId ?? 'unknown',
                'menu_id' => $menuId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_detail' => config('app.debug') ? $e->getMessage() : 'Error interno',
                'botones_permitidos' => []
            ], 500);
        }
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