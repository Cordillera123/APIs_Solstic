<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubmenuController extends Controller
{
    /**
     * Listar todos los submenús
     */
    public function index()
    {
        try {
            $submenus = DB::table('tbl_sub')
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
                ->orderBy('tbl_sub.sub_id')
                ->get();

            // Para cada submenú, obtener a qué menús está asociado
            foreach ($submenus as $submenu) {
                $menus = DB::table('tbl_men')
                    ->join('tbl_men_sub', 'tbl_men.men_id', '=', 'tbl_men_sub.men_id')
                    ->select('tbl_men.men_id', 'tbl_men.men_nom')
                    ->where('tbl_men_sub.sub_id', $submenu->sub_id)
                    ->get();
                
                $submenu->menus = $menus;
            }

            return response()->json([
                'status' => 'success',
                'submenus' => $submenus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener submenús: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo submenú y asignar a menú(s)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sub_nom' => 'required|string|max:100',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'sub_componente' => 'nullable|string|max:100',
            'sub_eje' => 'integer|min:1|max:9',
            'sub_ventana_directa' => 'boolean',
            'menu_ids' => 'required|array',
            'menu_ids.*' => 'integer|exists:tbl_men,men_id'
        ]);

        try {
            // Iniciar transacción
            DB::beginTransaction();

            // Valores por defecto
            $submenuData = [
                'sub_nom' => $validated['sub_nom'],
                'ico_id' => $validated['ico_id'] ?? null,
                'sub_componente' => $validated['sub_componente'] ?? null,
                'sub_eje' => $validated['sub_eje'] ?? 1,
                'sub_ventana_directa' => $validated['sub_ventana_directa'] ?? false,
                'sub_est' => false
            ];

            // Insertar submenú
            $submenuId = DB::table('tbl_sub')->insertGetId($submenuData, 'sub_id');

            // Asociar con los menús seleccionados
            foreach ($validated['menu_ids'] as $menuId) {
                DB::table('tbl_men_sub')->insert([
                    'men_id' => $menuId,
                    'sub_id' => $submenuId
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Submenú creado y asignado correctamente',
                'submenu_id' => $submenuId
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el submenú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un submenú específico con sus opciones
     */
    public function show($id)
    {
        try {
            $submenu = DB::table('tbl_sub')
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
                ->where('tbl_sub.sub_id', $id)
                ->first();

            if (!$submenu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submenú no encontrado'
                ], 404);
            }

            // Obtener menús asociados
            $menus = DB::table('tbl_men')
                ->join('tbl_men_sub', 'tbl_men.men_id', '=', 'tbl_men_sub.men_id')
                ->select('tbl_men.men_id', 'tbl_men.men_nom')
                ->where('tbl_men_sub.sub_id', $id)
                ->get();
            
            $submenu->menus = $menus;

            // Obtener opciones asociadas
            $opciones = DB::table('tbl_opc')
                ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_opc.opc_id', 
                    'tbl_opc.opc_nom', 
                    'tbl_opc.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_opc.opc_componente',
                    'tbl_opc.opc_eje',
                    'tbl_opc.opc_est'
                )
                ->where('tbl_sub_opc.sub_id', $id)
                ->get();
                
            $submenu->opciones = $opciones;

            return response()->json([
                'status' => 'success',
                'submenu' => $submenu
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el submenú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un submenú y sus asignaciones a menús
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'sub_nom' => 'required|string|max:100',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'sub_componente' => 'nullable|string|max:100',
            'sub_eje' => 'integer|min:1|max:9',
            'sub_ventana_directa' => 'boolean',
            'menu_ids' => 'array',
            'menu_ids.*' => 'integer|exists:tbl_men,men_id'
        ]);

        try {
            DB::beginTransaction();

            $submenu = DB::table('tbl_sub')->where('sub_id', $id)->first();
            
            if (!$submenu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submenú no encontrado'
                ], 404);
            }

            // Actualizar datos del submenú
            $submenuData = [
                'sub_nom' => $validated['sub_nom'],
                'ico_id' => $validated['ico_id'] ?? null,
                'sub_componente' => $validated['sub_componente'] ?? null,
                'sub_eje' => $validated['sub_eje'] ?? $submenu->sub_eje,
                'sub_ventana_directa' => $validated['sub_ventana_directa'] ?? $submenu->sub_ventana_directa
            ];

            DB::table('tbl_sub')
                ->where('sub_id', $id)
                ->update($submenuData);

            // Si se proporcionaron nuevos menús, actualizar las asignaciones
            if (isset($validated['menu_ids'])) {
                // Eliminar asignaciones actuales
                DB::table('tbl_men_sub')
                    ->where('sub_id', $id)
                    ->delete();
                
                // Crear nuevas asignaciones
                foreach ($validated['menu_ids'] as $menuId) {
                    DB::table('tbl_men_sub')->insert([
                        'men_id' => $menuId,
                        'sub_id' => $id
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Submenú actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el submenú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de un submenú (activar/desactivar)
     */
    public function toggleStatus($id)
    {
        try {
            $submenu = DB::table('tbl_sub')->where('sub_id', $id)->first();
            
            if (!$submenu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submenú no encontrado'
                ], 404);
            }

            // Cambiar el estado actual
            $newStatus = !$submenu->sub_est;
            
            DB::table('tbl_sub')
                ->where('sub_id', $id)
                ->update(['sub_est' => $newStatus]);

            $statusText = $newStatus ? 'activado' : 'desactivado';

            return response()->json([
                'status' => 'success',
                'message' => "Submenú {$statusText} correctamente",
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar el estado del submenú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un submenú
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            
            $submenu = DB::table('tbl_sub')->where('sub_id', $id)->first();
            
            if (!$submenu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submenú no encontrado'
                ], 404);
            }

            // Verificar si tiene opciones asociadas
            $opcionCount = DB::table('tbl_sub_opc')
                ->where('sub_id', $id)
                ->count();
                
            if ($opcionCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el submenú porque tiene opciones asociadas'
                ], 400);
            }

            // Eliminar relaciones en la tabla de menú-submenú
            DB::table('tbl_men_sub')
                ->where('sub_id', $id)
                ->delete();

            // Eliminar permisos asociados al submenú
            DB::table('tbl_perm')
                ->where('sub_id', $id)
                ->delete();

            // Eliminar el submenú
            DB::table('tbl_sub')
                ->where('sub_id', $id)
                ->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Submenú eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el submenú: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todos los submenús por menú
     */
    public function getByMenu($menuId)
    {
        try {
            $menu = DB::table('tbl_men')->where('men_id', $menuId)->first();
            
            if (!$menu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menú no encontrado'
                ], 404);
            }

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
                ->where('tbl_men_sub.men_id', $menuId)
                ->orderBy('tbl_sub.sub_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'menu_nombre' => $menu->men_nom,
                'submenus' => $submenus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener submenús: ' . $e->getMessage()
            ], 500);
        }
    }
}