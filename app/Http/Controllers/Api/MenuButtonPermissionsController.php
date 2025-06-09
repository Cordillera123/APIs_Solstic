<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
// ✅ IMPORTAR TUS MODELOS CORRECTOS
use App\Models\{Menu, Usuario, Perfil, Button, ButtonPermissionProfile};

class MenuButtonPermissionsController extends Controller
{
    /**
     * Obtener permisos de botones del usuario actual para un menú directo específico
     * Endpoint: GET /api/my-menu-button-permissions/{menuId}
     */
    public function getMyMenuButtonPermissions($menuId)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            Log::info("🔍 Obteniendo permisos de botones para menú directo", [
                'user_id' => $user->usu_id,
                'menu_id' => $menuId
            ]);

            // Verificar que el menú existe y es ventana directa
            $menu = Menu::find($menuId);
            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menú no encontrado',
                    'botones_permitidos' => []
                ], 404);
            }

            if (!$menu->men_ventana_directa) {
                return response()->json([
                    'success' => false,
                    'message' => 'El menú no es una ventana directa',
                    'botones_permitidos' => []
                ], 400);
            }

            // Obtener el perfil del usuario
            $perfil = $user->perfil;
            if (!$perfil) {
                return response()->json([
                    'success' => true,
                    'message' => 'Usuario sin perfil asignado',
                    'botones_permitidos' => []
                ]);
            }

            Log::info("👤 Usuario encontrado", [
                'user_id' => $user->usu_id,
                'perfil_id' => $perfil->per_id,
                'perfil_nombre' => $perfil->per_nom
            ]);

            // ✅ CORREGIDO: Obtener todos los botones del menú directo usando query builder
            // Ya que la relación many-to-many entre Menu y Button puede ser compleja
            $botones = DB::table('tbl_bot as b')
                ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                ->where('mb.men_id', $menuId)
                ->where('b.bot_activo', true)
                ->where('mb.men_bot_activo', true)
                ->select('b.*')
                ->get();

            Log::info("🔘 Botones encontrados para el menú", [
                'menu_id' => $menuId,
                'total_botones' => $botones->count(),
                'botones' => $botones->pluck('bot_codigo')->toArray()
            ]);

            // ✅ CORREGIDO: Obtener permisos del perfil para estos botones usando el modelo correcto
            $permisosIds = ButtonPermissionProfile::where('per_id', $perfil->per_id)
                ->where('men_id', $menuId) // ✅ IMPORTANTE: Filtrar por menú también
                ->whereIn('bot_id', $botones->pluck('bot_id'))
                ->where('perm_bot_per_activo', true)
                ->pluck('bot_id')
                ->toArray();

            Log::info("🔐 Permisos encontrados", [
                'perfil_id' => $perfil->per_id,
                'menu_id' => $menuId,
                'botones_con_permiso' => $permisosIds
            ]);

            // Construir respuesta con información de cada botón
            $botonesPermitidos = $botones->map(function($boton) use ($permisosIds) {
                $hasPermission = in_array($boton->bot_id, $permisosIds);
                
                return [
                    'bot_id' => $boton->bot_id,
                    'bot_codigo' => $boton->bot_codigo,
                    'bot_nom' => $boton->bot_nom, // ✅ CORREGIDO: usar bot_nom
                    'bot_tooltip' => $boton->bot_tooltip,
                    'bot_color' => $boton->bot_color,
                    'has_permission' => $hasPermission
                ];
            })->toArray();

            // Filtrar solo los que tienen permiso para la respuesta principal
            $botonesConPermiso = array_filter($botonesPermitidos, function($boton) {
                return $boton['has_permission'];
            });

            Log::info("✅ Permisos procesados", [
                'total_botones' => count($botonesPermitidos),
                'botones_permitidos' => count($botonesConPermiso)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos correctamente',
                'botones_permitidos' => array_values($botonesConPermiso), // Solo botones con permiso
                'menu_info' => [
                    'men_id' => $menu->men_id,
                    'men_nom' => $menu->men_nom,
                    'men_ventana_directa' => $menu->men_ventana_directa
                ],
                'user_info' => [
                    'usu_id' => $user->usu_id,
                    'perfil_id' => $perfil->per_id,
                    'perfil_nombre' => $perfil->per_nom
                ],
                'debug_info' => [
                    'total_botones_menu' => $botones->count(),
                    'botones_con_permiso' => count($botonesConPermiso),
                    'todos_los_botones' => $botonesPermitidos // Para debug
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo permisos de menú directo", [
                'user_id' => Auth::id(),
                'menu_id' => $menuId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'botones_permitidos' => []
            ], 500);
        }
    }

    /**
     * Verificar permiso específico de botón para menú directo
     * Endpoint: POST /api/check-menu-button-permission
     */
    public function checkMenuButtonPermission(Request $request)
    {
        try {
            $request->validate([
                'men_id' => 'required|integer',
                'bot_codigo' => 'required|string'
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $menuId = $request->men_id;
            $botCodigo = $request->bot_codigo;

            Log::info("🔍 Verificando permiso específico de menú", [
                'user_id' => $user->usu_id,
                'menu_id' => $menuId,
                'bot_codigo' => $botCodigo
            ]);

            // Verificar que el menú existe y es ventana directa
            $menu = Menu::find($menuId);
            if (!$menu || !$menu->men_ventana_directa) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Menú no válido o no es ventana directa'
                ]);
            }

            // Obtener el perfil del usuario
            $perfil = $user->perfil;
            if (!$perfil) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Usuario sin perfil asignado'
                ]);
            }

            // ✅ CORREGIDO: Buscar el botón usando query builder
            $boton = DB::table('tbl_bot as b')
                ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                ->where('b.bot_codigo', $botCodigo)
                ->where('mb.men_id', $menuId)
                ->where('b.bot_activo', true)
                ->where('mb.men_bot_activo', true)
                ->select('b.*')
                ->first();

            if (!$boton) {
                return response()->json([
                    'has_permission' => false,
                    'message' => 'Botón no encontrado en este menú'
                ]);
            }

            // ✅ CORREGIDO: Verificar permiso usando el modelo correcto
            $hasPermission = ButtonPermissionProfile::where('per_id', $perfil->per_id)
                ->where('men_id', $menuId)
                ->where('bot_id', $boton->bot_id)
                ->where('perm_bot_per_activo', true)
                ->exists();

            Log::info("🔐 Resultado verificación permiso", [
                'user_id' => $user->usu_id,
                'perfil_id' => $perfil->per_id,
                'menu_id' => $menuId,
                'bot_codigo' => $botCodigo,
                'bot_id' => $boton->bot_id,
                'has_permission' => $hasPermission
            ]);

            return response()->json([
                'has_permission' => $hasPermission,
                'message' => $hasPermission ? 'Permiso concedido' : 'Permiso denegado',
                'boton_info' => [
                    'bot_id' => $boton->bot_id,
                    'bot_codigo' => $boton->bot_codigo,
                    'bot_nom' => $boton->bot_nom // ✅ CORREGIDO
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error verificando permiso de menú", [
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
     * Obtener todos los permisos de botones del usuario actual
     * Endpoint: GET /api/my-permissions
     */
    public function getMyPermissions()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $perfil = $user->perfil;
            if (!$perfil) {
                return response()->json([
                    'success' => true,
                    'message' => 'Usuario sin perfil asignado',
                    'permissions' => []
                ]);
            }

            // ✅ CORREGIDO: Obtener todos los permisos usando el modelo correcto
            $permisos = ButtonPermissionProfile::where('per_id', $perfil->per_id)
                ->where('perm_bot_per_activo', true)
                ->with(['boton']) // ✅ CORREGIDO: usar relación 'boton'
                ->get()
                ->map(function($permiso) {
                    return [
                        'bot_id' => $permiso->boton->bot_id,
                        'bot_codigo' => $permiso->boton->bot_codigo,
                        'bot_nom' => $permiso->boton->bot_nom, // ✅ CORREGIDO
                        'bot_tooltip' => $permiso->boton->bot_tooltip,
                        'has_permission' => true
                    ];
                });

            Log::info("📋 Obteniendo todos los permisos del usuario", [
                'user_id' => $user->usu_id,
                'perfil_id' => $perfil->per_id,
                'total_permisos' => $permisos->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos correctamente',
                'permissions' => $permisos,
                'user_info' => [
                    'usu_id' => $user->usu_id,
                    'perfil_id' => $perfil->per_id,
                    'perfil_nombre' => $perfil->per_nom
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo todos los permisos", [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'permissions' => []
            ], 500);
        }
    }

    /**
     * Obtener información de menús directos con sus botones para un perfil
     * Endpoint: GET /api/menu-button-info/{perfilId}
     */
    public function getMenuButtonInfo($perfilId = null)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Si no se especifica perfil, usar el del usuario actual
            $targetPerfilId = $perfilId ?? $user->perfil?->per_id;
            
            if (!$targetPerfilId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo determinar el perfil'
                ], 400);
            }

            // ✅ CORREGIDO: Obtener menús directos usando query builder
            $menusDirectos = DB::table('tbl_men as m')
                ->where('m.men_ventana_directa', true)
                ->where('m.men_activo', true)
                ->get()
                ->map(function($menu) use ($targetPerfilId) {
                    // Obtener botones del menú
                    $botones = DB::table('tbl_bot as b')
                        ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                        ->where('mb.men_id', $menu->men_id)
                        ->where('b.bot_activo', true)
                        ->where('mb.men_bot_activo', true)
                        ->get()
                        ->map(function($boton) use ($targetPerfilId, $menu) {
                            // Verificar si el perfil tiene permiso
                            $hasPermission = ButtonPermissionProfile::where('per_id', $targetPerfilId)
                                ->where('men_id', $menu->men_id)
                                ->where('bot_id', $boton->bot_id)
                                ->where('perm_bot_per_activo', true)
                                ->exists();

                            return [
                                'bot_id' => $boton->bot_id,
                                'bot_codigo' => $boton->bot_codigo,
                                'bot_nom' => $boton->bot_nom, // ✅ CORREGIDO
                                'bot_tooltip' => $boton->bot_tooltip,
                                'bot_color' => $boton->bot_color,
                                'has_permission' => $hasPermission
                            ];
                        });

                    return [
                        'men_id' => $menu->men_id,
                        'men_nom' => $menu->men_nom,
                        'men_componente' => $menu->men_componente,
                        'botones' => $botones,
                        'total_botones' => $botones->count(),
                        'botones_permitidos' => $botones->where('has_permission', true)->count()
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Información obtenida correctamente',
                'menus_directos' => $menusDirectos,
                'perfil_id' => $targetPerfilId,
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