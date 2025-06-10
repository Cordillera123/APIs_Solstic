<?php
// app/Http/Controllers/API/IconController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IconController extends Controller
{
    /**
     * Obtener todos los iconos desde la base de datos
     */
    public function index()
    {
        try {
            Log::info('📊 Cargando iconos desde la base de datos');

            $icons = DB::table('tbl_ico')
                ->select(
                    'ico_id',
                    'ico_nom',
                    'ico_cat', 
                    'ico_lib',
                    'ico_des',
                    'ico_pro', // Si tienes este campo para iconos premium/pro
                    'ico_cre',
                    'ico_edi'
                )
                ->orderBy('ico_cat', 'asc')
                ->orderBy('ico_nom', 'asc')
                ->get();

            Log::info("✅ Iconos cargados: {$icons->count()} iconos encontrados");

            // Agrupar iconos por categoría para estadísticas
            $iconsByCategory = $icons->groupBy('ico_cat');
            $categoryStats = [];
            
            foreach ($iconsByCategory as $category => $categoryIcons) {
                $categoryStats[$category] = $categoryIcons->count();
            }

            return response()->json([
                'status' => 'success',
                'data' => $icons,
                'total' => $icons->count(),
                'categories' => array_keys($categoryStats),
                'category_stats' => $categoryStats,
                'message' => "Se cargaron {$icons->count()} iconos correctamente"
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Error al cargar iconos: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cargar los iconos',
                'error' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Obtener iconos por categoría
     */
    public function getByCategory($category)
    {
        try {
            $icons = DB::table('tbl_ico')
                ->select('ico_id', 'ico_nom', 'ico_cat', 'ico_lib', 'ico_des')
                ->where('ico_cat', $category)
                ->orderBy('ico_nom', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $icons,
                'category' => $category,
                'total' => $icons->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cargar iconos de la categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo icono
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'ico_nom' => 'required|string|max:100|unique:tbl_ico,ico_nom',
                'ico_cat' => 'required|string|max:50',
                'ico_lib' => 'required|string|max:50',
                'ico_des' => 'nullable|string|max:255',
                'ico_pro' => 'nullable|string|max:255'
            ]);

            $iconId = DB::table('tbl_ico')->insertGetId([
                'ico_nom' => $validated['ico_nom'],
                'ico_cat' => $validated['ico_cat'],
                'ico_lib' => $validated['ico_lib'],
                'ico_des' => $validated['ico_des'] ?? null,
                'ico_pro' => $validated['ico_pro'] ?? null,
                'ico_cre' => now(),
                'ico_edi' => now()
            ]);

            Log::info("✅ Nuevo icono creado: {$validated['ico_nom']} (ID: {$iconId})");

            $newIcon = DB::table('tbl_ico')->where('ico_id', $iconId)->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Icono creado correctamente',
                'data' => $newIcon
            ], 201);

        } catch (\Exception $e) {
            Log::error('❌ Error al crear icono: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el icono',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un icono existente
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'ico_nom' => 'required|string|max:100|unique:tbl_ico,ico_nom,' . $id . ',ico_id',
                'ico_cat' => 'required|string|max:50',
                'ico_lib' => 'required|string|max:50',
                'ico_des' => 'nullable|string|max:255',
                'ico_pro' => 'nullable|string|max:255'
            ]);

            $updated = DB::table('tbl_ico')
                ->where('ico_id', $id)
                ->update([
                    'ico_nom' => $validated['ico_nom'],
                    'ico_cat' => $validated['ico_cat'],
                    'ico_lib' => $validated['ico_lib'],
                    'ico_des' => $validated['ico_des'] ?? null,
                    'ico_pro' => $validated['ico_pro'] ?? null,
                    'ico_edi' => now()
                ]);

            if (!$updated) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Icono no encontrado'
                ], 404);
            }

            $updatedIcon = DB::table('tbl_ico')->where('ico_id', $id)->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Icono actualizado correctamente',
                'data' => $updatedIcon
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el icono',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un icono
     */
    public function destroy($id)
    {
        try {
            // Verificar si el icono está siendo usado
            $isUsed = DB::table('tbl_men')->where('ico_id', $id)->exists() ||
                     DB::table('tbl_sub')->where('ico_id', $id)->exists() ||
                     DB::table('tbl_opc')->where('ico_id', $id)->exists();

            if ($isUsed) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el icono porque está siendo usado'
                ], 400);
            }

            $deleted = DB::table('tbl_ico')->where('ico_id', $id)->delete();

            if (!$deleted) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Icono no encontrado'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Icono eliminado correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el icono',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de iconos
     */
    public function stats()
    {
        try {
            $totalIcons = DB::table('tbl_ico')->count();
            
            $categoriesWithCount = DB::table('tbl_ico')
                ->select('ico_cat', DB::raw('COUNT(*) as count'))
                ->groupBy('ico_cat')
                ->orderBy('count', 'desc')
                ->get();

            $librariesWithCount = DB::table('tbl_ico')
                ->select('ico_lib', DB::raw('COUNT(*) as count'))
                ->groupBy('ico_lib')
                ->orderBy('count', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_icons' => $totalIcons,
                    'categories' => $categoriesWithCount,
                    'libraries' => $librariesWithCount
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}