<?php
// ===== app/Http/Controllers/Api/CantonController.php =====
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CantonController extends Controller
{
    public function index()
    {
        try {
            $cantones = DB::table('gaf_canton as c')
                ->leftJoin('gaf_provin as p', 'c.canton_provin_codigo', '=', 'p.provin_codigo')
                ->select(
                    'c.canton_codigo',
                    'c.canton_nombre',
                    'c.canton_provin_codigo',
                    'p.provin_nombre'
                )
                ->orderBy('p.provin_nombre')
                ->orderBy('c.canton_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $cantones,
                'message' => 'Cantones obtenidos correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo cantones: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener cantones',
                'data' => []
            ], 500);
        }
    }

    public function listar()
    {
        try {
            // NUEVA LÓGICA: Obtener TODOS los cantones del país
            $cantones = DB::table('gaf_canton as c')
                ->leftJoin('gaf_provin as p', 'c.canton_provin_codigo', '=', 'p.provin_codigo')
                ->select(
                    'c.canton_codigo as value',
                    DB::raw("CONCAT(c.canton_nombre, ' (', p.provin_nombre, ')') as label"),
                    'c.canton_nombre',
                    'c.canton_provin_codigo',
                    'p.provin_nombre'
                )
                ->orderBy('c.canton_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $cantones,
                'message' => 'Lista de cantones obtenida correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error en cantones.listar: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener lista de cantones',
                'data' => []
            ], 500);
        }
    }

    public function getByProvincia($provinciaId)
    {
        try {
            $cantones = DB::table('gaf_canton')
                ->where('canton_provin_codigo', $provinciaId)
                ->select(
                    'canton_codigo as value',
                    'canton_nombre as label'
                )
                ->orderBy('canton_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $cantones,
                'message' => 'Cantones por provincia obtenidos correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo cantones por provincia: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener cantones por provincia',
                'data' => []
            ], 500);
        }
    }
}