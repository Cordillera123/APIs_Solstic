<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\IconController;
use App\Http\Controllers\API\MenuController;
use App\Http\Controllers\API\SubmenuController;
use App\Http\Controllers\API\OptionController;
use App\Http\Controllers\API\UsuarioController;
use App\Http\Controllers\API\PermissionsController;
use App\Http\Controllers\API\PerfilController;
use App\Http\Controllers\API\EstadoController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\API\ConfigController;

//rutas de configuracion
Route::apiResource('configs', ConfigController::class);

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas - SIN MIDDLEWARE DE PERMISOS (SOLO PARA TESTING)
Route::middleware('auth:sanctum')->group(function () {

    // === RUTAS DE AUTENTICACIÓN ===
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // === RUTAS DE ICONOS ===
    Route::get('/icons', [IconController::class, 'index']);
    Route::get('/icons/category/{category}', [IconController::class, 'getByCategory']);

    // === RUTAS DE MENÚ DEL USUARIO ===
    Route::get('/user-menu', [MenuController::class, 'getUserMenu']);

    // === RUTAS DE CONFIGURACIÓN DE VENTANAS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Configuracion')->group(function () {
        Route::put('/menu/{menuId}/direct-window', [MenuController::class, 'toggleMenuDirectWindow']);
        Route::put('/submenu/{submenuId}/direct-window', [MenuController::class, 'toggleSubmenuDirectWindow']);
        Route::put('/option/{optionId}/component', [MenuController::class, 'updateOptionComponent']);
        Route::get('/menu/{menuId}/config', [MenuController::class, 'getMenuConfig']);
        Route::get('/submenu/{submenuId}/config', [MenuController::class, 'getSubmenuConfig']);
    // });

    // === CRUD DE MENÚS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Menus')->group(function () {
        Route::apiResource('menus', MenuController::class);
        Route::put('/menus/{id}/toggle-status', [MenuController::class, 'toggleStatus']);
    // });

    // === CRUD DE SUBMENÚS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Submenus')->group(function () {
        Route::apiResource('submenus', SubmenuController::class);
        Route::put('/submenus/{id}/toggle-status', [SubmenuController::class, 'toggleStatus']);
        Route::get('/menus/{menuId}/submenus', [SubmenuController::class, 'getByMenu']);
    // });

    // === CRUD DE OPCIONES ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Opciones')->group(function () {
        Route::apiResource('options', OptionController::class);
        Route::put('/options/{id}/toggle-status', [OptionController::class, 'toggleStatus']);
        Route::get('/submenus/{submenuId}/options', [OptionController::class, 'getBySubmenu']);
    // });

    // === GESTIÓN DE USUARIOS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Usuarios')->group(function () {
        Route::apiResource('usuarios', UsuarioController::class);
        Route::put('/usuarios/{id}/toggle-status', [UsuarioController::class, 'toggleStatus']);
        Route::post('/usuarios/{id}/change-password', [UsuarioController::class, 'changePassword']);
        Route::post('/usuarios/{id}/reset-password', [UsuarioController::class, 'resetPassword']);
        Route::get('/usuarios/{id}/permissions', [UsuarioController::class, 'getUserPermissions']);
        Route::get('/usuarios-form-options', [UsuarioController::class, 'getFormOptions']);

        // === NUEVAS RUTAS DE PERMISOS POR USUARIO ===
        Route::get('/usuarios/{id}/permissions-detail', [UsuarioController::class, 'getPermissionsDetail']);
        Route::post('/usuarios/{id}/assign-permissions', [UsuarioController::class, 'assignPermissions']);
        Route::get('/usuarios/{id}/active-permissions', [UsuarioController::class, 'getActivePermissions']);
        Route::post('/usuarios/{id}/copy-permissions', [UsuarioController::class, 'copyUserPermissions']);
    // });

    // === GESTIÓN DE PERFILES/ROLES ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Perfiles')->group(function () {
        Route::apiResource('perfiles', PerfilController::class);
        Route::get('/perfiles/{id}/usuarios', [PerfilController::class, 'getUsuarios']);
        Route::put('/perfiles/{id}/toggle-status', [PerfilController::class, 'toggleStatus']);
        Route::get('/perfiles/{id}/usuarios', [PerfilController::class, 'getUsuarios']);
        Route::post('/perfiles/{id}/duplicate', [PerfilController::class, 'duplicate']);

        // Opciones para formularios
        Route::get('/perfiles-form-options', [PerfilController::class, 'getFormOptions']);
    // });

    // === GESTIÓN DE ESTADOS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Estados')->group(function () {
        Route::apiResource('estados', EstadoController::class);
    // });

    // === GESTIÓN DE PERMISOS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Sistema.Permisos')->group(function () {
        // Obtener perfiles
        Route::get('/permissions/profiles', [PermissionsController::class, 'getProfiles']);
        Route::get('/permissions/summary', [PermissionsController::class, 'getPermissionsSummary']);

        // Estructura de menús con permisos
        Route::get('/permissions/menu-structure/{perfilId}', [PermissionsController::class, 'getMenuStructureWithPermissions']);

        // Gestión de permisos individuales y masivos
        Route::post('/permissions/toggle', [PermissionsController::class, 'togglePermission']);
        Route::post('/permissions/bulk-assign', [PermissionsController::class, 'bulkAssignPermissions']);
        Route::post('/permissions/copy', [PermissionsController::class, 'copyPermissions']);

        // Validación de permisos
        Route::post('/permissions/validate', [PermissionsController::class, 'validateUserPermission']);
    // });

    // === RUTAS SIN RESTRICCIONES DE PERMISOS ESPECÍFICOS ===
    // (Disponibles para todos los usuarios autenticados)

    // Obtener opciones para formularios
    Route::get('/form-options', function () {
        return response()->json([
            'perfiles' => DB::table('tbl_per')->select('per_id as value', 'per_nom as label')->get(),
            'estados' => DB::table('tbl_est')->select('est_id as value', 'est_nom as label')->get(),
            'menus' => DB::table('tbl_men')->where('men_est', true)->select('men_id as value', 'men_nom as label')->get(),
            'submenus' => DB::table('tbl_sub')->where('sub_est', true)->select('sub_id as value', 'sub_nom as label')->get(),
            'opciones' => DB::table('tbl_opc')->where('opc_est', true)->select('opc_id as value', 'opc_nom as label')->get()
        ]);
    });

    // === RUTAS DE VALIDACIÓN DE PERMISOS (Utilidades) ===

    // Verificar si el usuario actual tiene un permiso específico
    Route::post('/check-permission', function(\Illuminate\Http\Request $request) {
        $permission = $request->input('permission');
        // Comentado temporalmente para testing
        // $hasPermission = \App\Http\Middleware\CheckPermissions::userHasPermission($permission);
        $hasPermission = true; // Temporal para testing

        return response()->json([
            'has_permission' => $hasPermission,
            'permission' => $permission
        ]);
    });

    // Obtener todos los permisos del usuario actual
    Route::get('/my-permissions', function() {
        $user = Auth::user();
        $authController = new AuthController();
        $permissions = $authController->getUserMenus($user->usu_id);

        return response()->json([
            'status' => 'success',
            'permissions' => $permissions
        ]);
    });
    // DEBUG - Quitar después de resolver
    Route::get('/debug/user-permissions/{userId}', [AuthController::class, 'debugUserPermissions']);
    // === RUTAS DE DASHBOARD/ESTADÍSTICAS ===
    // Route::middleware(\App\Http\Middleware\CheckPermissions::class.':Dashboard')->group(function () {
        Route::get('/dashboard/stats', function() {
            $stats = [
                'total_usuarios' => DB::table('tbl_usu')->count(),
                'usuarios_activos' => DB::table('tbl_usu')->where('est_id', 1)->count(),
                'total_perfiles' => DB::table('tbl_per')->count(),
                'total_menus' => DB::table('tbl_men')->where('men_est', true)->count(),
                'total_submenus' => DB::table('tbl_sub')->where('sub_est', true)->count(),
                'total_opciones' => DB::table('tbl_opc')->where('opc_est', true)->count(),
                'total_permisos' => DB::table('tbl_perm')->count()
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);
        });
    // });

});
