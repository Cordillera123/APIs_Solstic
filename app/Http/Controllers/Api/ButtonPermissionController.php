<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ButtonPermissionController extends Controller
{
    /**
     * Obtener permisos de botones para un perfil específico
     */
    public function getProfileButtonPermissions($perfilId)
    {
        try {
            Log::info("🔍 ButtonPermissionController: Obteniendo permisos para perfil {$perfilId}");

            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // ✅ OBTENER SOLO VENTANAS DIRECTAS con permisos de botones
            $menuStructure = $this->getDirectWindowsWithButtonPermissions($perfilId);

            Log::info("✅ Estructura de ventanas directas obtenida: " . count($menuStructure) . " módulos");

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos de botones obtenidos correctamente',
                'perfil' => $perfil,
                'menu_structure' => $menuStructure,
                'debug_info' => [
                    'total_ventanas_directas' => count($menuStructure),
                    'perfil_id' => $perfilId
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en ButtonPermissionController: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos de botones: ' . $e->getMessage()
            ], 500);
        }
    }
    private function getDirectWindowsWithButtonPermissions($perfilId)
    {
        Log::info("🔍 ButtonController: Obteniendo ventanas directas para perfil: {$perfilId}");

        // 1. Obtener permisos del perfil
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
            $tipoModulo = $this->determinarTipoModulo($permiso);
            
            if (!$tipoModulo['es_ventana_directa']) {
                continue; // Saltar si no es ventana directa
            }

            Log::info("✅ Procesando {$tipoModulo['tipo']}: Men:{$permiso->men_id}, Sub:{$permiso->sub_id}, Opc:{$permiso->opc_id}");

            // Obtener botones según el tipo de módulo
            $botones = $this->obtenerBotonesParaModulo($perfilId, $permiso, $tipoModulo['tipo']);

            if (empty($botones)) {
                Log::info("⚠️ No hay botones para este módulo, saltando...");
                continue; // Solo agregar módulos que tengan botones
            }

            // Agregar a la estructura jerárquica
            $this->agregarModuloAEstructura($menuStructure, $permiso, $botones, $tipoModulo['tipo']);
        }

        Log::info("✅ Estructura final construida: " . count($menuStructure) . " menús con ventanas directas");
        return $menuStructure;
    }
    private function determinarTipoModulo($permiso)
    {
        if ($permiso->opc_id) {
            // Es una opción
            $opcion = DB::table('tbl_opc')->where('opc_id', $permiso->opc_id)->first();
            return [
                'tipo' => 'opcion',
                'es_ventana_directa' => $opcion && $opcion->opc_ventana_directa,
                'info' => $opcion
            ];
        } elseif ($permiso->sub_id) {
            // Es un submenú
            $submenu = DB::table('tbl_sub')->where('sub_id', $permiso->sub_id)->first();
            return [
                'tipo' => 'submenu',
                'es_ventana_directa' => $submenu && $submenu->sub_ventana_directa,
                'info' => $submenu
            ];
        } else {
            // Es un menú
            $menu = DB::table('tbl_men')->where('men_id', $permiso->men_id)->first();
            return [
                'tipo' => 'menu',
                'es_ventana_directa' => $menu && $menu->men_ventana_directa,
                'info' => $menu
            ];
        }
    }
    private function obtenerBotonesParaModulo($perfilId, $permiso, $tipoModulo)
    {
        $botones = [];

        switch ($tipoModulo) {
            case 'opcion':
                // Botones de una opción (tabla tbl_opc_bot)
                $botones = DB::table('tbl_bot as b')
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
                    ->orderBy('ob.opc_bot_orden')
                    ->get();
                break;

            case 'submenu':
                // Botones de un submenú (tabla tbl_sub_bot)
                $botones = DB::table('tbl_bot as b')
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
                    ->orderBy('sb.sub_bot_orden')
                    ->get();
                break;

            case 'menu':
                // Botones de un menú (tabla tbl_men_bot)
                $botones = DB::table('tbl_bot as b')
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
                    ->orderBy('mb.men_bot_orden')
                    ->get();
                break;
        }

        // Verificar permisos del perfil para cada botón
        foreach ($botones as $boton) {
            $tienePermiso = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $permiso->men_id)
                ->where('sub_id', $permiso->sub_id)
                ->where('opc_id', $permiso->opc_id)
                ->where('bot_id', $boton->bot_id)
                ->where('perm_bot_per_activo', true)
                ->exists();

            $boton->has_permission = $tienePermiso;
        }

        Log::info("🔘 Botones encontrados para {$tipoModulo}: " . $botones->count());
        return $botones->toArray();
    }
    private function agregarModuloAEstructura(&$menuStructure, $permiso, $botones, $tipoModulo)
    {
        // Buscar o crear el menú principal
        $menuIndex = $this->buscarOCrearMenuEnEstructura($menuStructure, $permiso->men_id);

        switch ($tipoModulo) {
            case 'menu':
                // Agregar botones directamente al menú
                $menuStructure[$menuIndex]['botones'] = $botones;
                break;

            case 'submenu':
                // Buscar o crear el submenú
                $submenuIndex = $this->buscarOCrearSubmenuEnEstructura($menuStructure[$menuIndex], $permiso->sub_id);
                $menuStructure[$menuIndex]['submenus'][$submenuIndex]['botones'] = $botones;
                break;

            case 'opcion':
                // Buscar o crear submenú y opción
                $submenuIndex = $this->buscarOCrearSubmenuEnEstructura($menuStructure[$menuIndex], $permiso->sub_id);
                $opcionIndex = $this->buscarOCrearOpcionEnEstructura($menuStructure[$menuIndex]['submenus'][$submenuIndex], $permiso->opc_id);
                $menuStructure[$menuIndex]['submenus'][$submenuIndex]['opciones'][$opcionIndex]['botones'] = $botones;
                break;
        }
    }
    private function buscarOCrearMenuEnEstructura(&$menuStructure, $menId)
    {
        // Buscar menú existente
        foreach ($menuStructure as $index => $menu) {
            if ($menu['men_id'] === $menId) {
                return $index;
            }
        }

        // Crear nuevo menú
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
    private function buscarOCrearSubmenuEnEstructura(&$menu, $subId)
    {
        if (!isset($menu['submenus'])) {
            $menu['submenus'] = [];
        }

        // Buscar submenú existente
        foreach ($menu['submenus'] as $index => $submenu) {
            if ($submenu['sub_id'] === $subId) {
                return $index;
            }
        }

        // Crear nuevo submenú
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
    private function buscarOCrearOpcionEnEstructura(&$submenu, $opcId)
    {
        if (!isset($submenu['opciones'])) {
            $submenu['opciones'] = [];
        }

        // Buscar opción existente
        foreach ($submenu['opciones'] as $index => $opcion) {
            if ($opcion['opc_id'] === $opcId) {
                return $index;
            }
        }

        // Crear nueva opción
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
    private function getMenuStructureWithButtonPermissions($perfilId)
    {
        // Obtener menús con permisos del perfil
        $menus = DB::table('tbl_men')
            ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId) {
                $join->on('tbl_men.men_id', '=', 'pp.men_id')
                    ->where('pp.per_id', '=', $perfilId)
                    ->where('pp.perm_per_activo', '=', true);
            })
            ->join('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id', 'left')
            ->select(
                'tbl_men.men_id',
                'tbl_men.men_nom',
                'tbl_ico.ico_nom as ico_nombre'
            )
            ->where('tbl_men.men_activo', true)
            ->orderBy('tbl_men.men_orden')
            ->get();

        foreach ($menus as $menu) {
            // Obtener submenús con permisos
            $submenus = DB::table('tbl_sub')
                ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId, $menu) {
                    $join->on('tbl_sub.sub_id', '=', 'pp.sub_id')
                        ->where('pp.per_id', '=', $perfilId)
                        ->where('pp.men_id', '=', $menu->men_id)
                        ->where('pp.perm_per_activo', '=', true);
                })
                ->join('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_sub.sub_id',
                    'tbl_sub.sub_nom',
                    'tbl_ico.ico_nom as ico_nombre'
                )
                ->where('tbl_sub.sub_activo', true)
                ->orderBy('tbl_sub.sub_orden')
                ->get();

            foreach ($submenus as $submenu) {
                // Obtener opciones con permisos
                $opciones = DB::table('tbl_opc')
                    ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId, $menu, $submenu) {
                        $join->on('tbl_opc.opc_id', '=', 'pp.opc_id')
                            ->where('pp.per_id', '=', $perfilId)
                            ->where('pp.men_id', '=', $menu->men_id)
                            ->where('pp.sub_id', '=', $submenu->sub_id)
                            ->where('pp.perm_per_activo', '=', true);
                    })
                    ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                    ->select(
                        'tbl_opc.opc_id',
                        'tbl_opc.opc_nom',
                        'tbl_ico.ico_nom as ico_nombre'
                    )
                    ->where('tbl_opc.opc_activo', true)
                    ->orderBy('tbl_opc.opc_orden')
                    ->get();

                foreach ($opciones as $opcion) {
                    // Obtener botones disponibles para la opción
                    $botones = DB::table('tbl_bot')
                        ->join('tbl_opc_bot', 'tbl_bot.bot_id', '=', 'tbl_opc_bot.bot_id')
                        ->join('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id', 'left')
                        ->select(
                            'tbl_bot.bot_id',
                            'tbl_bot.bot_nom',
                            'tbl_bot.bot_codigo',
                            'tbl_bot.bot_color',
                            'tbl_bot.bot_tooltip',
                            'tbl_bot.bot_confirmacion',
                            'tbl_ico.ico_nom as ico_nombre'
                        )
                        ->where('tbl_opc_bot.opc_id', $opcion->opc_id)
                        ->where('tbl_opc_bot.opc_bot_activo', true)
                        ->where('tbl_bot.bot_activo', true)
                        ->orderBy('tbl_opc_bot.opc_bot_orden')
                        ->get();

                    // Para cada botón, verificar si el perfil tiene permiso
                    foreach ($botones as $boton) {
                        $tienePermiso = DB::table('tbl_perm_bot_perfil')
                            ->where('per_id', $perfilId)
                            ->where('men_id', $menu->men_id)
                            ->where('sub_id', $submenu->sub_id)
                            ->where('opc_id', $opcion->opc_id)
                            ->where('bot_id', $boton->bot_id)
                            ->where('perm_bot_per_activo', true)
                            ->exists();

                        $boton->has_permission = $tienePermiso;
                    }

                    $opcion->botones = $botones;
                }

                $submenu->opciones = $opciones;
            }

            $menu->submenus = $submenus;
        }

        return $menus;
    }

    /**
     * Asignar/revocar permiso de botón para un perfil
     */
    public function toggleButtonPermission(Request $request)
    {
        $validated = $request->validate([
            'per_id' => 'required|integer|exists:tbl_per,per_id',
            'men_id' => 'required|integer|exists:tbl_men,men_id',
            'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'bot_id' => 'required|integer|exists:tbl_bot,bot_id',
            'grant_permission' => 'required|boolean'
        ]);

        try {
            DB::beginTransaction();

            $permissionData = [
                'per_id' => $validated['per_id'],
                'men_id' => $validated['men_id'],
                'sub_id' => $validated['sub_id'],
                'opc_id' => $validated['opc_id'],
                'bot_id' => $validated['bot_id']
            ];

            $existingPermission = DB::table('tbl_perm_bot_perfil')
                ->where($permissionData)
                ->first();

            if ($validated['grant_permission']) {
                if (!$existingPermission) {
                    $permissionData['perm_bot_per_activo'] = true;
                    $permissionData['perm_bot_per_cre'] = now();
                    $permissionData['perm_bot_per_edi'] = now();
                    DB::table('tbl_perm_bot_perfil')->insert($permissionData);
                } else {
                    DB::table('tbl_perm_bot_perfil')
                        ->where($permissionData)
                        ->update([
                            'perm_bot_per_activo' => true,
                            'perm_bot_per_edi' => now()
                        ]);
                }
                $message = 'Permiso de botón otorgado correctamente';
            } else {
                if ($existingPermission) {
                    DB::table('tbl_perm_bot_perfil')
                        ->where($permissionData)
                        ->update([
                            'perm_bot_per_activo' => false,
                            'perm_bot_per_edi' => now()
                        ]);
                    $message = 'Permiso de botón revocado correctamente';
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
    public function bulkAssignButtonPermissions(Request $request)
    {
        $validated = $request->validate([
            'per_id' => 'required|integer|exists:tbl_per,per_id',
            'permissions' => 'required|array',
            'permissions.*.men_id' => 'required|integer|exists:tbl_men,men_id',
            'permissions.*.sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'permissions.*.opc_id' => 'required|integer|exists:tbl_opc,opc_id',
            'permissions.*.bot_id' => 'required|integer|exists:tbl_bot,bot_id',
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
                    'opc_id' => $permission['opc_id'],
                    'bot_id' => $permission['bot_id']
                ];

                $existingPermission = DB::table('tbl_perm_bot_perfil')
                    ->where($permissionData)
                    ->first();

                if ($permission['grant']) {
                    if (!$existingPermission) {
                        $permissionData['perm_bot_per_activo'] = true;
                        DB::table('tbl_perm_bot_perfil')->insert($permissionData);
                        $processedCount++;
                    } elseif (!$existingPermission->perm_bot_per_activo) {
                        DB::table('tbl_perm_bot_perfil')
                            ->where('perm_bot_per_id', $existingPermission->perm_bot_per_id)
                            ->update(['perm_bot_per_activo' => true]);
                        $processedCount++;
                    }
                } else {
                    if ($existingPermission && $existingPermission->perm_bot_per_activo) {
                        DB::table('tbl_perm_bot_perfil')
                            ->where('perm_bot_per_id', $existingPermission->perm_bot_per_id)
                            ->update(['perm_bot_per_activo' => false]);
                        $processedCount++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se procesaron {$processedCount} cambios de permisos de botones correctamente"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error en asignación masiva de permisos de botones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos de botones para un usuario específico
     */
    public function getUserButtonPermissions($usuarioId, $opcionId = null)
    {
        try {
            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Usar la vista optimizada que creamos en la base de datos
            $query = DB::table('vw_permisos_usuario_botones')
                ->where('usu_id', $usuarioId)
                ->where('tiene_permiso', true);

            if ($opcionId) {
                $query->where('opc_id', $opcionId);
            }

            $permisos = $query->orderBy('menu_nombre')
                ->orderBy('submenu_nombre')
                ->orderBy('opcion_nombre')
                ->orderBy('bot_orden')
                ->get();

            return response()->json([
                'status' => 'success',
                'usuario' => [
                    'usu_id' => $usuario->usu_id,
                    'usu_nom' => $usuario->usu_nom,
                    'usu_ape' => $usuario->usu_ape
                ],
                'permisos_botones' => $permisos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos de botones del usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar permiso específico de botón a un usuario
     */
    public function assignUserButtonPermission(Request $request)
    {
        $validated = $request->validate([
            'usu_id' => 'required|integer|exists:tbl_usu,usu_id',
            'men_id' => 'required|integer|exists:tbl_men,men_id',
            'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'opc_id' => 'required|integer|exists:tbl_opc,opc_id',
            'bot_id' => 'required|integer|exists:tbl_bot,bot_id',
            'perm_tipo' => 'required|string|in:C,D', // C=Conceder, D=Denegar
            'observaciones' => 'nullable|string',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio'
        ]);

        try {
            DB::beginTransaction();

            $permissionData = [
                'usu_id' => $validated['usu_id'],
                'men_id' => $validated['men_id'],
                'sub_id' => $validated['sub_id'],
                'opc_id' => $validated['opc_id'],
                'bot_id' => $validated['bot_id']
            ];

            // Verificar si ya existe un permiso específico
            $existingPermission = DB::table('tbl_perm_bot_usuario')
                ->where($permissionData)
                ->first();

            if ($existingPermission) {
                // Actualizar permiso existente
                DB::table('tbl_perm_bot_usuario')
                    ->where('perm_bot_usu_id', $existingPermission->perm_bot_usu_id)
                    ->update([
                        'perm_tipo' => $validated['perm_tipo'],
                        'perm_bot_usu_observaciones' => $validated['observaciones'] ?? null,
                        'perm_bot_usu_fecha_inicio' => $validated['fecha_inicio'] ?? null,
                        'perm_bot_usu_fecha_fin' => $validated['fecha_fin'] ?? null,
                        'perm_bot_usu_activo' => true,
                        'perm_bot_usu_creado_por' => Auth::id()
                    ]);
                $message = 'Permiso de botón actualizado correctamente';
            } else {
                // Crear nuevo permiso
                $permissionData = array_merge($permissionData, [
                    'perm_tipo' => $validated['perm_tipo'],
                    'perm_bot_usu_observaciones' => $validated['observaciones'] ?? null,
                    'perm_bot_usu_fecha_inicio' => $validated['fecha_inicio'] ?? null,
                    'perm_bot_usu_fecha_fin' => $validated['fecha_fin'] ?? null,
                    'perm_bot_usu_activo' => true,
                    'perm_bot_usu_creado_por' => Auth::id()
                ]);

                DB::table('tbl_perm_bot_usuario')->insert($permissionData);
                $message = 'Permiso de botón asignado correctamente';
            }

            DB::commit();

            $tipoTexto = $validated['perm_tipo'] === 'C' ? 'concedido' : 'denegado';

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'tipo_permiso' => $tipoTexto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al asignar permiso de botón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copiar permisos de botones de un perfil a otro
     */
    public function copyButtonPermissions(Request $request)
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

            // Si se especifica sobrescribir, eliminar permisos existentes
            if ($overwrite) {
                DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $targetId)
                    ->delete();
            }

            // Obtener permisos de botones del perfil origen
            $sourcePermissions = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $sourceId)
                ->where('perm_bot_per_activo', true)
                ->get();

            $copiedCount = 0;

            foreach ($sourcePermissions as $permission) {
                $newPermission = [
                    'per_id' => $targetId,
                    'men_id' => $permission->men_id,
                    'sub_id' => $permission->sub_id,
                    'opc_id' => $permission->opc_id,
                    'bot_id' => $permission->bot_id,
                    'perm_bot_per_activo' => true
                ];

                // Verificar si ya existe (solo si no se sobrescribe)
                if (!$overwrite) {
                    $exists = DB::table('tbl_perm_bot_perfil')
                        ->where($newPermission)
                        ->exists();

                    if ($exists) {
                        continue;
                    }
                }

                DB::table('tbl_perm_bot_perfil')->insert($newPermission);
                $copiedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se copiaron {$copiedCount} permisos de botones correctamente"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar permisos de botones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar si un usuario tiene permiso para un botón específico
     */
    public function validateUserButtonPermission(Request $request)
    {
        $validated = $request->validate([
            'usu_id' => 'required|integer|exists:tbl_usu,usu_id',
            'opc_id' => 'required|integer|exists:tbl_opc,opc_id',
            'bot_codigo' => 'required|string|exists:tbl_bot,bot_codigo'
        ]);

        try {
            // Obtener el botón por código
            $boton = DB::table('tbl_bot')
                ->where('bot_codigo', $validated['bot_codigo'])
                ->where('bot_activo', true)
                ->first();

            if (!$boton) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Botón no encontrado o inactivo'
                ], 404);
            }

            // Usar la vista para verificar permisos
            $tienePermiso = DB::table('vw_permisos_usuario_botones')
                ->where('usu_id', $validated['usu_id'])
                ->where('opc_id', $validated['opc_id'])
                ->where('bot_id', $boton->bot_id)
                ->where('tiene_permiso', true)
                ->exists();

            return response()->json([
                'status' => 'success',
                'has_permission' => $tienePermiso,
                'button_info' => [
                    'bot_id' => $boton->bot_id,
                    'bot_nom' => $boton->bot_nom,
                    'bot_codigo' => $boton->bot_codigo
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al validar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDirectWindowsButtonPermissions($perfilId)
    {
        try {
            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // ✅ LLAMAR AL MÉTODO PRIVADO QUE YA FILTRA VENTANAS DIRECTAS
            $menuStructure = $this->getMenuStructureWithButtonsDirectWindowsOnly($perfilId);

            return response()->json([
                'status' => 'success',
                'perfil' => $perfil,
                'menu_structure' => $menuStructure,
                'message' => 'Estructura filtrada para ventanas directas con botones'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener ventanas directas con botones: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * ✅ MÉTODO FINAL CORREGIDO - SIN DUPLICACIONES
     * Reemplaza el método getMenuStructureWithButtonsDirectWindowsOnly() en ButtonPermissionController.php
     */
    private function getMenuStructureWithButtonsDirectWindowsOnly($perfilId)
    {
        $menuStructure = [];

        // Query optimizada sin duplicaciones
        $elementosUnicos = DB::table('tbl_opc as o')
            ->join('tbl_sub_opc as so', 'o.opc_id', '=', 'so.opc_id')
            ->join('tbl_sub as s', 'so.sub_id', '=', 's.sub_id')
            ->join('tbl_men_sub as ms', 's.sub_id', '=', 'ms.sub_id')
            ->join('tbl_men as m', 'ms.men_id', '=', 'ms.men_id')
            ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId) {
                $join->on('o.opc_id', '=', 'pp.opc_id')
                    ->where('pp.per_id', '=', $perfilId)
                    ->where('pp.perm_per_activo', '=', true);
            })
            ->leftJoin('tbl_ico as ico_men', 'm.ico_id', '=', 'ico_men.ico_id')
            ->leftJoin('tbl_ico as ico_sub', 's.ico_id', '=', 'ico_sub.ico_id')
            ->leftJoin('tbl_ico as ico_opc', 'o.ico_id', '=', 'ico_opc.ico_id')
            ->where('o.opc_activo', true)
            ->where('s.sub_activo', true)
            ->where('m.men_activo', true)
            ->where(function ($query) {
                // Filtro: Al menos uno debe tener ventana directa
                $query->where('m.men_ventana_directa', true)
                    ->orWhere('s.sub_ventana_directa', true)
                    ->orWhere('o.opc_ventana_directa', true);
            })
            ->whereExists(function ($query) {
                // Filtro: Solo opciones que tengan botones activos
                $query->select(DB::raw(1))
                    ->from('tbl_opc_bot as ob')
                    ->join('tbl_bot as b', 'ob.bot_id', '=', 'b.bot_id')
                    ->whereColumn('ob.opc_id', 'o.opc_id')
                    ->where('ob.opc_bot_activo', true)
                    ->where('b.bot_activo', true);
            })
            ->select(
                // Datos del menú
                'm.men_id',
                'm.men_nom',
                'm.men_componente',
                'm.men_ventana_directa',
                'm.men_orden',
                'ico_men.ico_nom as men_ico_nombre',
                // Datos del submenú
                's.sub_id',
                's.sub_nom',
                's.sub_componente',
                's.sub_ventana_directa',
                's.sub_orden',
                'ico_sub.ico_nom as sub_ico_nombre',
                // Datos de la opción
                'o.opc_id',
                'o.opc_nom',
                'o.opc_componente',
                'o.opc_ventana_directa',
                'o.opc_orden',
                'ico_opc.ico_nom as opc_ico_nombre'
            )
            ->distinct() // ✅ CLAVE: Evitar duplicados desde la query
            ->orderBy('m.men_orden')
            ->orderBy('s.sub_orden')
            ->orderBy('o.opc_orden')
            ->get();

        // Agrupar datos sin duplicaciones
        $menusMap = collect($elementosUnicos)->groupBy('men_id');

        foreach ($menusMap as $menuId => $menuItems) {
            $firstMenuItem = $menuItems->first();

            $menuData = [
                'men_id' => $firstMenuItem->men_id,
                'men_nom' => $firstMenuItem->men_nom,
                'men_componente' => $firstMenuItem->men_componente,
                'men_ventana_directa' => (bool) $firstMenuItem->men_ventana_directa,
                'ico_nombre' => $firstMenuItem->men_ico_nombre,
                'has_permission' => true,
                'submenus' => []
            ];

            $submenusMap = $menuItems->groupBy('sub_id');

            foreach ($submenusMap as $submenuId => $submenuItems) {
                $firstSubmenuItem = $submenuItems->first();

                $submenuData = [
                    'sub_id' => $firstSubmenuItem->sub_id,
                    'sub_nom' => $firstSubmenuItem->sub_nom,
                    'sub_componente' => $firstSubmenuItem->sub_componente,
                    'sub_ventana_directa' => (bool) $firstSubmenuItem->sub_ventana_directa,
                    'ico_nombre' => $firstSubmenuItem->sub_ico_nombre,
                    'has_permission' => true,
                    'opciones' => []
                ];

                $opcionesMap = $submenuItems->groupBy('opc_id');

                foreach ($opcionesMap as $opcionId => $opcionItems) {
                    $opcionItem = $opcionItems->first();

                    $opcionData = [
                        'opc_id' => $opcionItem->opc_id,
                        'opc_nom' => $opcionItem->opc_nom,
                        'opc_componente' => $opcionItem->opc_componente,
                        'opc_ventana_directa' => (bool) $opcionItem->opc_ventana_directa,
                        'ico_nombre' => $opcionItem->opc_ico_nombre,
                        'has_permission' => true,
                        'botones' => []
                    ];

                    // Obtener botones (query separada para evitar multiplicación)
                    $botones = $this->getBotonesWithPermissions(
                        $perfilId,
                        $firstMenuItem->men_id,
                        $firstSubmenuItem->sub_id,
                        $opcionItem->opc_id
                    );

                    if (!empty($botones)) {
                        $opcionData['botones'] = $botones;
                        $submenuData['opciones'][] = $opcionData;
                    }
                }

                if (!empty($submenuData['opciones'])) {
                    $menuData['submenus'][] = $submenuData;
                }
            }

            if (!empty($menuData['submenus'])) {
                $menuStructure[] = $menuData;
            }
        }

        return $menuStructure;
    }

    private function getSubmenuStructureWithButtonsDirectWindowsOnly($perfilId, $menuId)
    {
        $submenus = [];

        $submenuData = DB::table('tbl_sub as s')
            ->join('tbl_men_sub as ms', 's.sub_id', '=', 'ms.sub_id')
            ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId, $menuId) {
                $join->on('s.sub_id', '=', 'pp.sub_id')
                    ->where('pp.per_id', '=', $perfilId)
                    ->where('pp.men_id', '=', $menuId)
                    ->where('pp.perm_per_activo', '=', true)
                    ->whereNull('pp.opc_id');
            })
            ->join('tbl_ico as i', 's.ico_id', '=', 'i.ico_id', 'left')
            ->where('ms.men_id', $menuId)
            ->where('s.sub_activo', true)
            ->where('s.sub_ventana_directa', true) // ✅ FILTRO: Solo ventanas directas
            ->select(
                's.sub_id',
                's.sub_nom',
                's.sub_componente',
                's.sub_ventana_directa',
                'i.ico_nom as ico_nombre'
            )
            ->orderBy('s.sub_orden')
            ->get();

        foreach ($submenuData as $submenu) {
            $submenuArray = [
                'sub_id' => $submenu->sub_id,
                'sub_nom' => $submenu->sub_nom,
                'sub_componente' => $submenu->sub_componente,
                'sub_ventana_directa' => $submenu->sub_ventana_directa,
                'ico_nombre' => $submenu->ico_nombre,
                'has_permission' => true,
                'opciones' => []
            ];

            // Obtener opciones con ventana directa
            $opciones = $this->getOptionStructureWithButtonsDirectWindowsOnly($perfilId, $menuId, $submenu->sub_id);
            $submenuArray['opciones'] = $opciones;

            $submenus[] = $submenuArray;
        }

        return $submenus;
    }
    private function getOptionStructureWithButtonsDirectWindowsOnly($perfilId)
    {
        $menuStructure = [];

        // ✅ PASO 1: Obtener todos los elementos únicos con ventana directa
        $elementosUnicos = DB::table('tbl_opc as o')
            ->join('tbl_sub_opc as so', 'o.opc_id', '=', 'so.opc_id')
            ->join('tbl_sub as s', 'so.sub_id', '=', 's.sub_id')
            ->join('tbl_men_sub as ms', 's.sub_id', '=', 'ms.sub_id')
            ->join('tbl_men as m', 'ms.men_id', '=', 'm.men_id')
            ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId) {
                $join->on('o.opc_id', '=', 'pp.opc_id')
                    ->where('pp.per_id', '=', $perfilId)
                    ->where('pp.perm_per_activo', '=', true);
            })
            ->leftJoin('tbl_ico as ico_men', 'm.ico_id', '=', 'ico_men.ico_id')
            ->leftJoin('tbl_ico as ico_sub', 's.ico_id', '=', 'ico_sub.ico_id')
            ->leftJoin('tbl_ico as ico_opc', 'o.ico_id', '=', 'ico_opc.ico_id')
            ->where('o.opc_activo', true)
            ->where('s.sub_activo', true)
            ->where('m.men_activo', true)
            ->where(function ($query) {
                // ✅ FILTRO: Al menos uno debe tener ventana directa
                $query->where('m.men_ventana_directa', true)
                    ->orWhere('s.sub_ventana_directa', true)
                    ->orWhere('o.opc_ventana_directa', true);
            })
            ->whereExists(function ($query) {
                // ✅ FILTRO: Solo si la opción tiene botones activos
                $query->select(DB::raw(1))
                    ->from('tbl_opc_bot as ob')
                    ->join('tbl_bot as b', 'ob.bot_id', '=', 'b.bot_id')
                    ->whereColumn('ob.opc_id', 'o.opc_id')
                    ->where('ob.opc_bot_activo', true)
                    ->where('b.bot_activo', true);
            })
            ->select(
                // Datos del menú
                'm.men_id',
                'm.men_nom',
                'm.men_componente',
                'm.men_ventana_directa',
                'm.men_orden',
                'ico_men.ico_nom as men_ico_nombre',

                // Datos del submenú
                's.sub_id',
                's.sub_nom',
                's.sub_componente',
                's.sub_ventana_directa',
                's.sub_orden',
                'ico_sub.ico_nom as sub_ico_nombre',

                // Datos de la opción
                'o.opc_id',
                'o.opc_nom',
                'o.opc_componente',
                'o.opc_ventana_directa',
                'o.opc_orden',
                'ico_opc.ico_nom as opc_ico_nombre'
            )
            ->orderBy('m.men_orden')
            ->orderBy('s.sub_orden')
            ->orderBy('o.opc_orden')
            ->get();

        // ✅ PASO 2: Agrupar y construir estructura sin duplicaciones
        $menusMap = collect($elementosUnicos)->groupBy('men_id');

        foreach ($menusMap as $menuId => $menuItems) {
            $firstMenuItem = $menuItems->first();

            // Crear estructura del menú
            $menuData = [
                'men_id' => $firstMenuItem->men_id,
                'men_nom' => $firstMenuItem->men_nom,
                'men_componente' => $firstMenuItem->men_componente,
                'men_ventana_directa' => (bool) $firstMenuItem->men_ventana_directa,
                'ico_nombre' => $firstMenuItem->men_ico_nombre,
                'has_permission' => true,
                'submenus' => []
            ];

            // Agrupar por submenús
            $submenusMap = $menuItems->groupBy('sub_id');

            foreach ($submenusMap as $submenuId => $submenuItems) {
                $firstSubmenuItem = $submenuItems->first();

                // Crear estructura del submenú
                $submenuData = [
                    'sub_id' => $firstSubmenuItem->sub_id,
                    'sub_nom' => $firstSubmenuItem->sub_nom,
                    'sub_componente' => $firstSubmenuItem->sub_componente,
                    'sub_ventana_directa' => (bool) $firstSubmenuItem->sub_ventana_directa,
                    'ico_nombre' => $firstSubmenuItem->sub_ico_nombre,
                    'has_permission' => true,
                    'opciones' => []
                ];

                // Agrupar por opciones
                $opcionesMap = $submenuItems->groupBy('opc_id');

                foreach ($opcionesMap as $opcionId => $opcionItems) {
                    $opcionItem = $opcionItems->first();

                    // Crear estructura de la opción
                    $opcionData = [
                        'opc_id' => $opcionItem->opc_id,
                        'opc_nom' => $opcionItem->opc_nom,
                        'opc_componente' => $opcionItem->opc_componente,
                        'opc_ventana_directa' => (bool) $opcionItem->opc_ventana_directa,
                        'ico_nombre' => $opcionItem->opc_ico_nombre,
                        'has_permission' => true,
                        'botones' => []
                    ];

                    // ✅ PASO 3: Obtener botones de la opción (SIN JOIN que cause duplicación)
                    $botones = $this->getBotonesWithPermissions(
                        $perfilId,
                        $firstMenuItem->men_id,
                        $firstSubmenuItem->sub_id,
                        $opcionItem->opc_id
                    );

                    $opcionData['botones'] = $botones;

                    // Solo agregar si tiene botones
                    if (!empty($botones)) {
                        $submenuData['opciones'][] = $opcionData;
                    }
                }

                // Solo agregar submenú si tiene opciones
                if (!empty($submenuData['opciones'])) {
                    $menuData['submenus'][] = $submenuData;
                }
            }

            // Solo agregar menú si tiene submenús con opciones
            if (!empty($menuData['submenus'])) {
                $menuStructure[] = $menuData;
            }
        }

        return $menuStructure;
    }

    /**
     * ✅ MÉTODO AUXILIAR OPTIMIZADO: Obtener botones con permisos (sin duplicaciones)
     */
    private function getBotonesWithPermissions($perfilId, $menuId, $submenuId, $opcionId)
    {
        return DB::table('tbl_bot as b')
            ->join('tbl_opc_bot as ob', 'b.bot_id', '=', 'ob.bot_id')
            ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
            ->leftJoin('tbl_perm_bot_perfil as pbp', function ($join) use ($perfilId, $menuId, $submenuId, $opcionId) {
                $join->on('b.bot_id', '=', 'pbp.bot_id')
                    ->where('pbp.per_id', '=', $perfilId)
                    ->where('pbp.men_id', '=', $menuId)
                    ->where('pbp.sub_id', '=', $submenuId)
                    ->where('pbp.opc_id', '=', $opcionId)
                    ->where('pbp.perm_bot_per_activo', '=', true);
            })
            ->where('ob.opc_id', $opcionId)
            ->where('ob.opc_bot_activo', true)
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
                'i.ico_lib as ico_libreria',
                'ob.opc_bot_orden',
                DB::raw('CASE WHEN pbp.bot_id IS NOT NULL THEN true ELSE false END as has_permission')
            )
            ->orderBy('ob.opc_bot_orden')
            ->orderBy('b.bot_orden')
            ->get()
            ->map(function ($boton) {
                return [
                    'bot_id' => $boton->bot_id,
                    'bot_nom' => $boton->bot_nom,
                    'bot_codigo' => $boton->bot_codigo,
                    'bot_color' => $boton->bot_color,
                    'bot_tooltip' => $boton->bot_tooltip,
                    'bot_confirmacion' => (bool) $boton->bot_confirmacion,
                    'bot_mensaje_confirmacion' => $boton->bot_mensaje_confirmacion,
                    'ico_nombre' => $boton->ico_nombre,
                    'ico_libreria' => $boton->ico_libreria,
                    'has_permission' => (bool) $boton->has_permission
                ];
            })
            ->toArray();
    }

    public function getButtonPermissionsSummary()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->select('per_id', 'per_nom')
                ->where('per_activo', true)
                ->get();

            foreach ($perfiles as $perfil) {
                $totalBotones = DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfil->per_id)
                    ->where('perm_bot_per_activo', true)
                    ->count();

                $botonesPorTipo = DB::table('tbl_perm_bot_perfil as pbp')
                    ->join('tbl_bot as b', 'pbp.bot_id', '=', 'b.bot_id')
                    ->where('pbp.per_id', $perfil->per_id)
                    ->where('pbp.perm_bot_per_activo', true)
                    ->groupBy('b.bot_codigo')
                    ->select('b.bot_codigo', DB::raw('count(*) as total'))
                    ->pluck('total', 'bot_codigo')
                    ->toArray();

                $perfil->button_permissions_summary = [
                    'total_botones' => $totalBotones,
                    'por_tipo' => $botonesPorTipo
                ];
            }

            return response()->json([
                'status' => 'success',
                'perfiles' => $perfiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener resumen de permisos de botones: ' . $e->getMessage()
            ], 500);
        }
    }
}
