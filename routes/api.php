<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IconController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\SubmenuController;
use App\Http\Controllers\Api\OptionController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\PermissionsController;
use App\Http\Controllers\Api\PerfilController;
use App\Http\Controllers\Api\DirectModulesController;
use App\Http\Controllers\Api\EstadoController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\ButtonController;
use App\Http\Controllers\Api\ButtonPermissionController;
use App\Http\Controllers\Api\MenuButtonPermissionsController;
use App\Http\Controllers\Api\UserButtonPermissionController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TipoOficinaController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;


// Rutas de configuración
Route::apiResource('configs', ConfigController::class);

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {

    // === RUTAS DE AUTENTICACIÓN ===
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    // ⚠️ RUTA TEMPORAL - ELIMINAR DESPUÉS


    // === RUTAS DE ICONOS ===
    Route::get('/icons', [IconController::class, 'index']);
    Route::get('/icons/category/{category}', [IconController::class, 'getByCategory']);

    // === RUTAS DE MENÚ DEL USUARIO ===
    Route::get('/user-menu', [MenuController::class, 'getUserMenu']);

    // === RUTAS DE CONFIGURACIÓN DE VENTANAS ===
    Route::put('/menu/{menuId}/direct-window', [MenuController::class, 'toggleMenuDirectWindow']);
    Route::put('/submenu/{submenuId}/direct-window', [MenuController::class, 'toggleSubmenuDirectWindow']);
    Route::put('/option/{optionId}/component', [MenuController::class, 'updateOptionComponent']);
    Route::get('/menu/{menuId}/config', [MenuController::class, 'getMenuConfig']);
    Route::get('/submenu/{submenuId}/config', [MenuController::class, 'getSubmenuConfig']);

    // === CRUD DE MENÚS ===
    Route::apiResource('menus', MenuController::class);
    Route::put('/menus/{id}/toggle-status', [MenuController::class, 'toggleStatus']);

    // === CRUD DE SUBMENÚS ===
    Route::apiResource('submenus', SubmenuController::class);
    Route::put('/submenus/{id}/toggle-status', [SubmenuController::class, 'toggleStatus']);
    Route::get('/menus/{menuId}/submenus', [SubmenuController::class, 'getByMenu']);

    // === CRUD DE OPCIONES ===
    Route::apiResource('options', OptionController::class);
    Route::put('/options/{id}/toggle-status', [OptionController::class, 'toggleStatus']);
    Route::get('/submenus/{submenuId}/options', [OptionController::class, 'getBySubmenu']);

    // === CRUD DE BOTONES ===
    Route::apiResource('buttons', ButtonController::class);
    Route::put('/buttons/{id}/toggle-status', [ButtonController::class, 'toggleStatus']);
    Route::get('/buttons/with-usage', [ButtonController::class, 'getAllWithUsage']);
    Route::get('/options/{optionId}/buttons', [ButtonController::class, 'getByOption']);
    Route::post('/options/{optionId}/assign-buttons', [ButtonController::class, 'assignToOption']);
    //GESTION DE MODULOS DIRECTOS ===
    Route::get('/direct-modules/perfiles', [DirectModulesController::class, 'getPerfilesWithDirectModules']);
    Route::get('/direct-modules/perfiles/{perfilId}', [DirectModulesController::class, 'getModulosDirectosForPerfil']);
    Route::post('/direct-modules/perfiles/{perfilId}/toggle', [DirectModulesController::class, 'toggleModuloDirectoAccess']);
    Route::post('/direct-modules/perfiles/{perfilId}/asignacion-masiva', [DirectModulesController::class, 'asignacionMasiva']);
    Route::post('/direct-modules/copiar-configuracion', [DirectModulesController::class, 'copiarConfiguracion']);

    // === ✅ NUEVAS RUTAS PARA PERMISOS DE MENÚS DIRECTOS ===
    Route::get('/my-menu-button-permissions/{menuId}', [MenuButtonPermissionsController::class, 'getMyMenuButtonPermissions']);
    Route::post('/check-menu-button-permission', [MenuButtonPermissionsController::class, 'checkMenuButtonPermission']);
    Route::get('/my-permissions', [MenuButtonPermissionsController::class, 'getMyPermissions']);
    Route::get('/menu-button-info/{perfilId?}', [MenuButtonPermissionsController::class, 'getMenuButtonInfo']);

    // === RUTAS DE PERMISOS DE BOTONES (ADMINISTRACIÓN) ===
    Route::get('/button-permissions/profiles/{perfilId}/direct-windows', function ($perfilId) {
        try {
            Log::info("🔍 Consultando ventanas directas para perfil: {$perfilId}");

            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            $menuStructure = [];

            // NIVEL 1: MENÚS CON VENTANA DIRECTA Y BOTONES
            $menusDirectos = DB::table('tbl_men as m')
                ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId) {
                    $join->on('m.men_id', '=', 'pp.men_id')
                        ->where('pp.per_id', '=', $perfilId)
                        ->where('pp.perm_per_activo', '=', true)
                        ->whereNull('pp.sub_id')
                        ->whereNull('pp.opc_id');
                })
                ->leftJoin('tbl_ico as ico_men', 'm.ico_id', '=', 'ico_men.ico_id')
                ->where('m.men_activo', true)
                ->where('m.men_ventana_directa', true)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('tbl_men_bot as mb')
                        ->join('tbl_bot as b', 'mb.bot_id', '=', 'b.bot_id')
                        ->whereColumn('mb.men_id', 'm.men_id')
                        ->where('mb.men_bot_activo', true)
                        ->where('b.bot_activo', true);
                })
                ->select(
                    'm.men_id',
                    'm.men_nom',
                    'm.men_componente',
                    'm.men_ventana_directa',
                    'm.men_orden',
                    'ico_men.ico_nom as men_ico_nombre'
                )
                ->distinct()
                ->orderBy('m.men_orden')
                ->get();

            foreach ($menusDirectos as $menu) {
                $menuData = [
                    'men_id' => $menu->men_id,
                    'men_nom' => $menu->men_nom,
                    'men_componente' => $menu->men_componente,
                    'men_ventana_directa' => true,
                    'ico_nombre' => $menu->men_ico_nombre,
                    'has_permission' => true,
                    'botones' => [],
                    'submenus' => []
                ];

                // Obtener botones del menú directo
                $botones = DB::table('tbl_bot as b')
                    ->join('tbl_men_bot as mb', 'b.bot_id', '=', 'mb.bot_id')
                    ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                    ->leftJoin('tbl_perm_bot_perfil as pbp', function ($join) use ($perfilId, $menu) {
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
                        'b.bot_id',
                        'b.bot_nom',
                        'b.bot_codigo',
                        'b.bot_color',
                        'b.bot_tooltip',
                        'b.bot_confirmacion',
                        'b.bot_mensaje_confirmacion',
                        'i.ico_nom as ico_nombre',
                        'mb.men_bot_orden',
                        DB::raw('CASE WHEN pbp.bot_id IS NOT NULL THEN true ELSE false END as has_permission')
                    )
                    ->orderBy('mb.men_bot_orden')
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
                            'has_permission' => (bool) $boton->has_permission
                        ];
                    })
                    ->toArray();

                if (!empty($botones)) {
                    $menuData['botones'] = $botones;
                    $menuStructure[] = $menuData;
                }
            }

            // NIVEL 2: SUBMENÚS CON VENTANA DIRECTA Y BOTONES
            $submenusDirectos = DB::table('tbl_sub as s')
                ->join('tbl_men_sub as ms', 's.sub_id', '=', 'ms.sub_id')
                ->join('tbl_men as m', 'ms.men_id', '=', 'm.men_id')
                ->join('tbl_perm_perfil as pp', function ($join) use ($perfilId) {
                    $join->on('s.sub_id', '=', 'pp.sub_id')
                        ->where('pp.per_id', '=', $perfilId)
                        ->where('pp.perm_per_activo', '=', true)
                        ->whereNull('pp.opc_id');
                })
                ->leftJoin('tbl_ico as ico_men', 'm.ico_id', '=', 'ico_men.ico_id')
                ->leftJoin('tbl_ico as ico_sub', 's.ico_id', '=', 'ico_sub.ico_id')
                ->where('s.sub_activo', true)
                ->where('m.men_activo', true)
                ->where('s.sub_ventana_directa', true)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('tbl_sub_bot as sb')
                        ->join('tbl_bot as b', 'sb.bot_id', '=', 'b.bot_id')
                        ->whereColumn('sb.sub_id', 's.sub_id')
                        ->where('sb.sub_bot_activo', true)
                        ->where('b.bot_activo', true);
                })
                ->select(
                    'm.men_id',
                    'm.men_nom',
                    'm.men_componente',
                    'm.men_ventana_directa',
                    'm.men_orden',
                    'ico_men.ico_nom as men_ico_nombre',
                    's.sub_id',
                    's.sub_nom',
                    's.sub_componente',
                    's.sub_ventana_directa',
                    's.sub_orden',
                    'ico_sub.ico_nom as sub_ico_nombre'
                )
                ->distinct()
                ->orderBy('m.men_orden')
                ->orderBy('s.sub_orden')
                ->get();

            // Agrupar submenús por menú
            $submenusByMenu = $submenusDirectos->groupBy('men_id');

            foreach ($submenusByMenu as $menuId => $submenus) {
                $firstSubmenu = $submenus->first();

                $menuIndex = collect($menuStructure)->search(function ($menu) use ($menuId) {
                    return $menu['men_id'] == $menuId;
                });

                if ($menuIndex === false) {
                    $menuData = [
                        'men_id' => $firstSubmenu->men_id,
                        'men_nom' => $firstSubmenu->men_nom,
                        'men_componente' => $firstSubmenu->men_componente,
                        'men_ventana_directa' => (bool) $firstSubmenu->men_ventana_directa,
                        'ico_nombre' => $firstSubmenu->men_ico_nombre,
                        'has_permission' => true,
                        'submenus' => []
                    ];
                    $menuStructure[] = $menuData;
                    $menuIndex = count($menuStructure) - 1;
                }

                foreach ($submenus as $submenu) {
                    $submenuData = [
                        'sub_id' => $submenu->sub_id,
                        'sub_nom' => $submenu->sub_nom,
                        'sub_componente' => $submenu->sub_componente,
                        'sub_ventana_directa' => true,
                        'ico_nombre' => $submenu->sub_ico_nombre,
                        'has_permission' => true,
                        'botones' => [],
                        'opciones' => []
                    ];

                    // Obtener botones del submenú directo
                    $botones = DB::table('tbl_bot as b')
                        ->join('tbl_sub_bot as sb', 'b.bot_id', '=', 'sb.bot_id')
                        ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                        ->leftJoin('tbl_perm_bot_perfil as pbp', function ($join) use ($perfilId, $firstSubmenu, $submenu) {
                            $join->on('b.bot_id', '=', 'pbp.bot_id')
                                ->where('pbp.per_id', '=', $perfilId)
                                ->where('pbp.men_id', '=', $firstSubmenu->men_id)
                                ->where('pbp.sub_id', '=', $submenu->sub_id)
                                ->whereNull('pbp.opc_id')
                                ->where('pbp.perm_bot_per_activo', '=', true);
                        })
                        ->where('sb.sub_id', $submenu->sub_id)
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
                            DB::raw('CASE WHEN pbp.bot_id IS NOT NULL THEN true ELSE false END as has_permission')
                        )
                        ->orderBy('sb.sub_bot_orden')
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
                                'has_permission' => (bool) $boton->has_permission
                            ];
                        })
                        ->toArray();

                    if (!empty($botones)) {
                        $submenuData['botones'] = $botones;
                        $menuStructure[$menuIndex]['submenus'][] = $submenuData;
                    }
                }
            }

            // NIVEL 3: OPCIONES CON VENTANA DIRECTA Y BOTONES
            $opcionesDirectas = DB::table('tbl_opc as o')
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
                ->where('o.opc_ventana_directa', true)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('tbl_opc_bot as ob')
                        ->join('tbl_bot as b', 'ob.bot_id', '=', 'b.bot_id')
                        ->whereColumn('ob.opc_id', 'o.opc_id')
                        ->where('ob.opc_bot_activo', true)
                        ->where('b.bot_activo', true);
                })
                ->select(
                    'm.men_id',
                    'm.men_nom',
                    'm.men_componente',
                    'm.men_ventana_directa',
                    'm.men_orden',
                    'ico_men.ico_nom as men_ico_nombre',
                    's.sub_id',
                    's.sub_nom',
                    's.sub_componente',
                    's.sub_ventana_directa',
                    's.sub_orden',
                    'ico_sub.ico_nom as sub_ico_nombre',
                    'o.opc_id',
                    'o.opc_nom',
                    'o.opc_componente',
                    'o.opc_ventana_directa',
                    'o.opc_orden',
                    'ico_opc.ico_nom as opc_ico_nombre'
                )
                ->distinct()
                ->orderBy('m.men_orden')
                ->orderBy('s.sub_orden')
                ->orderBy('o.opc_orden')
                ->get();

            // Procesar opciones
            $opcionesByMenu = $opcionesDirectas->groupBy(['men_id', 'sub_id']);

            foreach ($opcionesByMenu as $menuId => $submenuGroup) {
                foreach ($submenuGroup as $submenuId => $opciones) {
                    $firstOption = $opciones->first();

                    $menuIndex = collect($menuStructure)->search(function ($menu) use ($menuId) {
                        return $menu['men_id'] == $menuId;
                    });

                    if ($menuIndex === false) {
                        $menuData = [
                            'men_id' => $firstOption->men_id,
                            'men_nom' => $firstOption->men_nom,
                            'men_componente' => $firstOption->men_componente,
                            'men_ventana_directa' => (bool) $firstOption->men_ventana_directa,
                            'ico_nombre' => $firstOption->men_ico_nombre,
                            'has_permission' => true,
                            'submenus' => []
                        ];
                        $menuStructure[] = $menuData;
                        $menuIndex = count($menuStructure) - 1;
                    }

                    $submenuIndex = collect($menuStructure[$menuIndex]['submenus'])->search(function ($submenu) use ($submenuId) {
                        return $submenu['sub_id'] == $submenuId;
                    });

                    if ($submenuIndex === false) {
                        $submenuData = [
                            'sub_id' => $firstOption->sub_id,
                            'sub_nom' => $firstOption->sub_nom,
                            'sub_componente' => $firstOption->sub_componente,
                            'sub_ventana_directa' => (bool) $firstOption->sub_ventana_directa,
                            'ico_nombre' => $firstOption->sub_ico_nombre,
                            'has_permission' => true,
                            'opciones' => []
                        ];
                        $menuStructure[$menuIndex]['submenus'][] = $submenuData;
                        $submenuIndex = count($menuStructure[$menuIndex]['submenus']) - 1;
                    }

                    foreach ($opciones as $opcion) {
                        $opcionData = [
                            'opc_id' => $opcion->opc_id,
                            'opc_nom' => $opcion->opc_nom,
                            'opc_componente' => $opcion->opc_componente,
                            'opc_ventana_directa' => true,
                            'ico_nombre' => $opcion->opc_ico_nombre,
                            'has_permission' => true,
                            'botones' => []
                        ];

                        // Obtener botones de la opción
                        $botones = DB::table('tbl_bot as b')
                            ->join('tbl_opc_bot as ob', 'b.bot_id', '=', 'ob.bot_id')
                            ->leftJoin('tbl_ico as i', 'b.ico_id', '=', 'i.ico_id')
                            ->leftJoin('tbl_perm_bot_perfil as pbp', function ($join) use ($perfilId, $firstOption, $opcion) {
                                $join->on('b.bot_id', '=', 'pbp.bot_id')
                                    ->where('pbp.per_id', '=', $perfilId)
                                    ->where('pbp.men_id', '=', $firstOption->men_id)
                                    ->where('pbp.sub_id', '=', $firstOption->sub_id)
                                    ->where('pbp.opc_id', '=', $opcion->opc_id)
                                    ->where('pbp.perm_bot_per_activo', '=', true);
                            })
                            ->where('ob.opc_id', $opcion->opc_id)
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
                                    'has_permission' => (bool) $boton->has_permission
                                ];
                            })
                            ->toArray();

                        if (!empty($botones)) {
                            $opcionData['botones'] = $botones;
                            $menuStructure[$menuIndex]['submenus'][$submenuIndex]['opciones'][] = $opcionData;
                        }
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'perfil' => $perfil,
                'menu_structure' => $menuStructure
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en button-permissions direct-windows: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener ventanas directas: ' . $e->getMessage()
            ], 500);
        }
    });
    Route::get('/button-permissions/profiles/{perfilId}', [ButtonPermissionController::class, 'getProfileButtonPermissions']);
    Route::post('/button-permissions/toggle', [ButtonPermissionController::class, 'toggleButtonPermission']);
    Route::post('/button-permissions/bulk-assign', [ButtonPermissionController::class, 'bulkAssignButtonPermissions']);
    Route::post('/button-permissions/copy', [ButtonPermissionController::class, 'copyButtonPermissions']);
    Route::get('/button-permissions/users/{usuarioId}', [ButtonPermissionController::class, 'getUserButtonPermissions']);
    Route::get('/button-permissions/users/{usuarioId}/options/{opcionId}', [ButtonPermissionController::class, 'getUserButtonPermissions']);
    Route::post('/button-permissions/assign-user', [ButtonPermissionController::class, 'assignUserButtonPermission']);
    Route::post('/button-permissions/validate', [ButtonPermissionController::class, 'validateUserButtonPermission']);
    Route::get('/button-permissions/summary', [ButtonPermissionController::class, 'getButtonPermissionsSummary']);

    // === GESTIÓN DE USUARIOS ===
    Route::apiResource('usuarios', UsuarioController::class);
    Route::put('/usuarios/{id}/toggle-status', [UsuarioController::class, 'toggleStatus']);
    Route::patch('/usuarios/{id}/reactivate', [UsuarioController::class, 'reactivate']);
    Route::post('/usuarios/{id}/change-password', [UsuarioController::class, 'changePassword']);
    Route::post('/usuarios/{id}/reset-password', [UsuarioController::class, 'resetPassword']);
    Route::get('/usuarios/{id}/permissions', [UsuarioController::class, 'getUserPermissions']);
    Route::get('/usuarios-form-options', [UsuarioController::class, 'getFormOptions']);
    Route::get('/usuarios/{id}/permissions-detail', [UsuarioController::class, 'getPermissionsDetail']);
    Route::post('/usuarios/{id}/assign-permissions', [UsuarioController::class, 'assignPermissions']);
    Route::get('/usuarios/{id}/active-permissions', [UsuarioController::class, 'getActivePermissions']);
    Route::post('/usuarios/{id}/copy-permissions', [UsuarioController::class, 'copyUserPermissions']);

    // === GESTIÓN DE PERFILES/ROLES ===
    Route::apiResource('perfiles', PerfilController::class);
    Route::get('/perfiles/{id}/usuarios', [PerfilController::class, 'getUsuarios']);
    Route::put('/perfiles/{id}/toggle-status', [PerfilController::class, 'toggleStatus']);
    Route::post('/perfiles/{id}/duplicate', [PerfilController::class, 'duplicate']);
    Route::get('/perfiles-form-options', [PerfilController::class, 'getFormOptions']);
    Route::get('/perfiles/{perfil_id}/modulos-directos-disponibles', [PermissionsController::class, 'getModulosDirectosDisponibles']);
    Route::post('/perfiles/{perfil_id}/toggle-acceso-botones', [PermissionsController::class, 'toggleAccesoBotones']);
    Route::post('/perfiles/{perfil_id}/inicializar-modulos-directos', [PermissionsController::class, 'inicializarModulosDirectos']);
    Route::get('/perfiles/{perfil_id}/diagnosticar-modulos-directos', [PermissionsController::class, 'diagnosticarPerfilSinModulosDirectos']);
    Route::post('/perfiles/{perfil_id}/asignar-permisos-basicos', [PermissionsController::class, 'asignarPermisosBasicosVentanasDirectas']);
    // === GESTIÓN DE ESTADOS ===
    Route::apiResource('estados', EstadoController::class);
    // === GESTIÓN DE TIPOS DE OFICINA ===
    Route::prefix('tipos-oficina')->group(function () {
    Route::get('/', [TipoOficinaController::class, 'index']);
    Route::post('/', [TipoOficinaController::class, 'store']);
    Route::get('/activos', [TipoOficinaController::class, 'activos']);
    Route::get('/{id}', [TipoOficinaController::class, 'show']);
    Route::put('/{id}', [TipoOficinaController::class, 'update']);
    Route::delete('/{id}', [TipoOficinaController::class, 'destroy']);
});
    // === GESTIÓN DE PERMISOS DE BOTONES POR USUARIO ===
    Route::get('/user-button-permissions/profiles/{perfilId}/users', [UserButtonPermissionController::class, 'getUsersByProfile']);
    Route::get('/user-button-permissions/users/{usuarioId}', [UserButtonPermissionController::class, 'getUserButtonPermissions']);
    Route::post('/user-button-permissions/toggle', [UserButtonPermissionController::class, 'toggleUserButtonPermission']);
    Route::delete('/user-button-permissions/remove-customization', [UserButtonPermissionController::class, 'removeUserCustomization']);
    Route::delete('/user-button-permissions/users/{usuarioId}/reset', [UserButtonPermissionController::class, 'resetUserCustomizations']);
    Route::post('/user-button-permissions/copy', [UserButtonPermissionController::class, 'copyUserCustomizations']);
    Route::get('/user-button-permissions/users/{usuarioId}/effective-permissions/{opcId}', [UserButtonPermissionController::class, 'getUserEffectiveButtonPermissions']);
    Route::post('/user-button-permissions/users/{usuarioId}/check-permission', [UserButtonPermissionController::class, 'checkUserButtonPermission']);
    Route::post('/user-button-permissions/copy', [UserButtonPermissionController::class, 'copyUserCustomizations']);
    Route::post('/user-button-permissions/users/{usuarioId}/check-menu-permission', [UserButtonPermissionController::class, 'checkUserMenuButtonPermission']);
    // === GESTIÓN DE PERMISOS ===
    Route::get('/permissions/profiles', [PermissionsController::class, 'getProfiles']);
    Route::get('/permissions/summary', [PermissionsController::class, 'getPermissionsSummary']);
    Route::get('/permissions/menu-structure/{perfilId}', [PermissionsController::class, 'getMenuStructureWithPermissions']);
    Route::get('/permissions/menu-structure-buttons/{perfilId}', [PermissionsController::class, 'getMenuStructureWithButtonPermissions']);
    Route::post('/permissions/toggle', [PermissionsController::class, 'togglePermission']);
    Route::post('/permissions/bulk-assign', [PermissionsController::class, 'bulkAssignPermissions']);
    Route::post('/permissions/copy', [PermissionsController::class, 'copyPermissions']);
    Route::post('/permissions/validate', [PermissionsController::class, 'validateUserPermission']);
    Route::get('/permissions/user-buttons/{opcId}', [PermissionsController::class, 'getUserButtonPermissionsForOption']);
    Route::post('/permissions/validate-multiple-buttons', [PermissionsController::class, 'validateMultipleButtonPermissions']);
    Route::get('/permissions/button-stats', [PermissionsController::class, 'getButtonUsageStats']);
    Route::post('/permissions/sync-button-permissions', [PermissionsController::class, 'syncButtonPermissionsFromOptions']);
    Route::post('/permissions/configuracion-masiva-botones', [PermissionsController::class, 'configuracionMasivaBotones']);



    // === RUTAS DE UTILIDADES ===
    Route::get('/form-options', function () {
        return response()->json([
            'perfiles' => DB::table('tbl_per')->select('per_id as value', 'per_nom as label')->where('per_activo', true)->get(),
            'estados' => DB::table('tbl_est')->select('est_id as value', 'est_nom as label')->where('est_activo', true)->get(),
            'menus' => DB::table('tbl_men')->where('men_activo', true)->select('men_id as value', 'men_nom as label')->get(),
            'submenus' => DB::table('tbl_sub')->where('sub_activo', true)->select('sub_id as value', 'sub_nom as label')->get(),
            'opciones' => DB::table('tbl_opc')->where('opc_activo', true)->select('opc_id as value', 'opc_nom as label')->get(),
            'botones' => DB::table('tbl_bot')->where('bot_activo', true)->select('bot_id as value', 'bot_nom as label', 'bot_codigo', 'bot_color')->orderBy('bot_orden')->get(),
            'iconos' => DB::table('tbl_ico')->select('ico_id as value', 'ico_nom as label', 'ico_lib as libreria')->get()
        ]);
    });

    // === RUTAS DE VALIDACIÓN DE PERMISOS ===
    Route::post('/check-permission', function (\Illuminate\Http\Request $request) {
        $permission = $request->input('permission');
        $hasPermission = true; // Temporal para testing
        return response()->json([
            'has_permission' => $hasPermission,
            'permission' => $permission
        ]);
    });

    Route::post('/check-button-permission', function (\Illuminate\Http\Request $request) {
        $validated = $request->validate([
            'opc_id' => 'required|integer|exists:tbl_opc,opc_id',
            'bot_codigo' => 'required|string|exists:tbl_bot,bot_codigo'
        ]);

        $userId = Auth::id();
        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $hasPermission = DB::table('vw_permisos_usuario_botones')
            ->where('usu_id', $userId)
            ->where('opc_id', $validated['opc_id'])
            ->where('bot_codigo', $validated['bot_codigo'])
            ->where('tiene_permiso', true)
            ->exists();

        return response()->json([
            'has_permission' => $hasPermission,
            'opc_id' => $validated['opc_id'],
            'bot_codigo' => $validated['bot_codigo']
        ]);
    });

    Route::get('/my-button-permissions/{opcId}', function ($opcId) {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $botones = DB::table('vw_permisos_usuario_botones')
            ->where('usu_id', $userId)
            ->where('opc_id', $opcId)
            ->where('tiene_permiso', true)
            ->select('bot_id', 'boton_nombre', 'bot_codigo', 'bot_color', 'bot_tooltip', 'bot_confirmacion', 'bot_mensaje_confirmacion')
            ->orderBy('bot_orden')
            ->get();

        return response()->json([
            'status' => 'success',
            'opc_id' => $opcId,
            'botones_permitidos' => $botones
        ]);
    });

    // === RUTAS DE DASHBOARD/ESTADÍSTICAS ===
    Route::get('/dashboard/stats', function () {
        $stats = [
            'total_usuarios' => DB::table('tbl_usu')->count(),
            'usuarios_activos' => DB::table('tbl_usu')->where('est_id', 1)->count(),
            'total_perfiles' => DB::table('tbl_per')->count(),
            'total_menus' => DB::table('tbl_men')->where('men_activo', true)->count(),
            'total_submenus' => DB::table('tbl_sub')->where('sub_activo', true)->count(),
            'total_opciones' => DB::table('tbl_opc')->where('opc_activo', true)->count(),
            'total_permisos' => DB::table('tbl_perm_perfil')->count(),
            'total_botones' => DB::table('tbl_bot')->where('bot_activo', true)->count(),
            'total_asignaciones_botones' => DB::table('tbl_opc_bot')->where('opc_bot_activo', true)->count(),
            'total_permisos_botones_perfil' => DB::table('tbl_perm_bot_perfil')->where('perm_bot_per_activo', true)->count(),
            'total_permisos_botones_usuario' => DB::table('tbl_perm_bot_usuario')->where('perm_bot_usu_activo', true)->count()
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    });
});
