<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Menu;

class MenuController extends Controller
{
    /**
     * Obtener el menú dinámico completo para el usuario autenticado
     * incluyendo información para la apertura directa de ventanas
     */
    public function getUserMenu(Request $request)
    {
        // Obtener el usuario autenticado
        $user = $request->user();
        $perfilId = $user->per_id;

        // Consulta para obtener los menús permitidos para el perfil del usuario
        // Incluye campos para ventana directa y componente
        $menus = DB::table('tbl_men')
            ->join('tbl_perm', 'tbl_men.men_id', '=', 'tbl_perm.men_id')
            ->join('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id', 'left')
            ->where('tbl_perm.per_id', $perfilId)
            ->select(
                'tbl_men.men_id',
                'tbl_men.men_nom',
                'tbl_men.ico_id',
                'tbl_ico.ico_nom as ico_nombre',
                'tbl_ico.ico_lib as ico_libreria',
                DB::raw('CASE WHEN tbl_men.men_ventana_directa IS NULL THEN false ELSE tbl_men.men_ventana_directa END as men_ventana_directa'),
                'tbl_men.men_componente'
            )
            ->where('tbl_men.men_eje', 1)
            ->distinct()
            ->get();

        // Para cada menú, obtener sus submenús
        foreach ($menus as $menu) {
            $submenus = DB::table('tbl_sub')
                ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                ->join('tbl_perm', function ($join) use ($perfilId, $menu) {
                    $join->on('tbl_sub.sub_id', '=', 'tbl_perm.sub_id')
                        ->where('tbl_perm.per_id', '=', $perfilId)
                        ->where('tbl_perm.men_id', '=', $menu->men_id);
                })
                ->join('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_sub.sub_id',
                    'tbl_sub.sub_nom',
                    'tbl_sub.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    DB::raw('CASE WHEN tbl_sub.sub_ventana_directa IS NULL THEN false ELSE tbl_sub.sub_ventana_directa END as sub_ventana_directa'),
                    'tbl_sub.sub_componente'
                )
                ->where('tbl_men_sub.men_id', $menu->men_id)
                ->where('tbl_sub.sub_eje', 1)
                ->distinct()
                ->get();

            // Para cada submenú, obtener sus opciones
            foreach ($submenus as $submenu) {
                $opciones = DB::table('tbl_opc')
                    ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                    ->join('tbl_perm', function ($join) use ($perfilId, $menu, $submenu) {
                        $join->on('tbl_opc.opc_id', '=', 'tbl_perm.opc_id')
                            ->where('tbl_perm.per_id', '=', $perfilId)
                            ->where('tbl_perm.men_id', '=', $menu->men_id)
                            ->where('tbl_perm.sub_id', '=', $submenu->sub_id);
                    })
                    ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                    ->select(
                        'tbl_opc.opc_id',
                        'tbl_opc.opc_nom',
                        'tbl_opc.ico_id',
                        'tbl_ico.ico_nom as ico_nombre',
                        'tbl_ico.ico_lib as ico_libreria',
                        'tbl_opc.opc_componente',
                        'tbl_opc.opc_ventana_directa' // ✅ AGREGAR ESTA LÍNEA
                    )
                    ->where('tbl_sub_opc.sub_id', $submenu->sub_id)
                    ->where('tbl_opc.opc_eje', 1)
                    ->distinct()
                    ->get();

                $submenu->opciones = $opciones;
            }

            $menu->submenus = $submenus;
        }

        // Obtener todos los iconos para referencia rápida
        $iconos = DB::table('tbl_ico')
            ->select('ico_id', 'ico_nom', 'ico_lib', 'ico_cat')
            ->get();

        return response()->json([
            'menus' => $menus,
            'iconos' => $iconos,
            'status' => 'success'
        ]);
    }

    /**
     * Actualizar preferencia de ventana directa para un menú
     */
    public function toggleMenuDirectWindow(Request $request, $menuId)
    {
        $validated = $request->validate([
            'ventana_directa' => 'required|boolean',
            'componente' => 'nullable|string|max:100'
        ]);

        try {
            DB::table('tbl_men')
                ->where('men_id', $menuId)
                ->update([
                    'men_ventana_directa' => $validated['ventana_directa'],
                    'men_componente' => $validated['componente']
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Preferencia de ventana directa actualizada correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar la preferencia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar preferencia de ventana directa para un submenú
     */
    public function toggleSubmenuDirectWindow(Request $request, $submenuId)
    {
        $validated = $request->validate([
            'ventana_directa' => 'required|boolean',
            'componente' => 'nullable|string|max:100'
        ]);

        try {
            DB::table('tbl_sub')
                ->where('sub_id', $submenuId)
                ->update([
                    'sub_ventana_directa' => $validated['ventana_directa'],
                    'sub_componente' => $validated['componente']
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Preferencia de ventana directa actualizada correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar la preferencia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar componente para una opción
     */
    public function updateOptionComponent(Request $request, $optionId)
    {
        $validated = $request->validate([
            'componente' => 'nullable|string|max:100'
        ]);

        try {
            DB::table('tbl_opc')
                ->where('opc_id', $optionId)
                ->update([
                    'opc_componente' => $validated['componente']
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Componente actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el componente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener la configuración actual de un menú (para formularios de administración)
     */
    public function getMenuConfig($menuId)
    {
        try {
            $menu = DB::table('tbl_men')
                ->select('men_id', 'men_nom', 'men_ventana_directa', 'men_componente', 'ico_id')
                ->where('men_id', $menuId)
                ->first();

            if (!$menu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menú no encontrado'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'menu' => $menu
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener la configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener la configuración actual de un submenú (para formularios de administración)
     */
    public function getSubmenuConfig($submenuId)
    {
        try {
            $submenu = DB::table('tbl_sub')
                ->select('sub_id', 'sub_nom', 'sub_ventana_directa', 'sub_componente', 'ico_id')
                ->where('sub_id', $submenuId)
                ->first();

            if (!$submenu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submenú no encontrado'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'submenu' => $submenu
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener la configuración: ' . $e->getMessage()
            ], 500);
        }
    }
    public function index()
    {
        try {
            $menus = DB::table('tbl_men')
                ->join('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_men.men_id',
                    'tbl_men.men_nom',
                    'tbl_men.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_men.men_ventana_directa',
                    'tbl_men.men_componente',
                    'tbl_men.men_eje',
                    'tbl_men.men_est'
                )
                ->orderBy('tbl_men.men_id')
                ->get();

            return response()->json([
                'status' => 'success',
                'menus' => $menus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener menús: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo menú
     */
    /**
     * Crear un nuevo menú
     */


    public function store(Request $request)
    {
        $validated = $request->validate([
            'men_nom' => 'required|string|max:100',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'men_componente' => 'nullable|string|max:100',
            'men_eje' => 'integer|min:1|max:9',
            'men_ventana_directa' => 'boolean',
            'men_est' => 'boolean'
        ]);

        try {
            // Valores por defecto
            $validated['men_eje'] = $validated['men_eje'] ?? 1;
            $validated['men_ventana_directa'] = $validated['men_ventana_directa'] ?? false;
            $validated['men_est'] = $validated['men_est'] ?? true;

            $menu = Menu::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Menú creado correctamente',
                'menu_id' => $menu->men_id,
                'menu' => $menu
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el menú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un menú específico con sus submenús
     */
    public function show($id)
    {
        try {
            $menu = DB::table('tbl_men')
                ->join('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_men.men_id',
                    'tbl_men.men_nom',
                    'tbl_men.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_men.men_ventana_directa',
                    'tbl_men.men_componente',
                    'tbl_men.men_eje',
                    'tbl_men.men_est'
                )
                ->where('tbl_men.men_id', $id)
                ->first();

            if (!$menu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menú no encontrado'
                ], 404);
            }

            // Obtener submenús asociados
            $submenus = DB::table('tbl_sub')
                ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
                ->join('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_sub.sub_id',
                    'tbl_sub.sub_nom',
                    'tbl_sub.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_sub.sub_ventana_directa',
                    'tbl_sub.sub_componente',
                    'tbl_sub.sub_eje',
                    'tbl_sub.sub_est'
                )
                ->where('tbl_men_sub.men_id', $id)
                ->get();

            $menu->submenus = $submenus;

            return response()->json([
                'status' => 'success',
                'menu' => $menu
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el menú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un menú
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'men_nom' => 'required|string|max:100',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'men_componente' => 'nullable|string|max:100',
            'men_eje' => 'integer|min:1|max:9',
            'men_ventana_directa' => 'boolean'
        ]);

        try {
            $menu = DB::table('tbl_men')->where('men_id', $id)->first();

            if (!$menu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menú no encontrado'
                ], 404);
            }

            DB::table('tbl_men')
                ->where('men_id', $id)
                ->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Menú actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el menú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de un menú (activar/desactivar)
     */
    public function toggleStatus($id)
    {
        try {
            $menu = DB::table('tbl_men')->where('men_id', $id)->first();

            if (!$menu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menú no encontrado'
                ], 404);
            }

            // Cambiar el estado actual
            $newStatus = !$menu->men_est;

            DB::table('tbl_men')
                ->where('men_id', $id)
                ->update(['men_est' => $newStatus]);

            $statusText = $newStatus ? 'activado' : 'desactivado';

            return response()->json([
                'status' => 'success',
                'message' => "Menú {$statusText} correctamente",
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar el estado del menú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un menú
     */
    public function destroy($id)
    {
        try {
            $menu = DB::table('tbl_men')->where('men_id', $id)->first();

            if (!$menu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menú no encontrado'
                ], 404);
            }

            // Verificar si tiene submenús asociados
            $submenuCount = DB::table('tbl_men_sub')
                ->where('men_id', $id)
                ->count();

            if ($submenuCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el menú porque tiene submenús asociados'
                ], 400);
            }

            // Eliminar permisos asociados al menú
            DB::table('tbl_perm')
                ->where('men_id', $id)
                ->delete();

            // Eliminar el menú
            DB::table('tbl_men')
                ->where('men_id', $id)
                ->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Menú eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el menú: ' . $e->getMessage()
            ], 500);
        }
    }
}
