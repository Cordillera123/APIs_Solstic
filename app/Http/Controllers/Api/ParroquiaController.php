<?php
// ===== app/Http/Controllers/Api/ParroquiaController.php =====
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParroquiaController extends Controller
{
    public function index()
    {
        try {
            $parroquias = DB::table('gaf_parroq as pr')
                ->leftJoin('gaf_canton as c', 'pr.parroq_canton_codigo', '=', 'c.canton_codigo')
                ->leftJoin('gaf_provin as p', 'c.canton_provin_codigo', '=', 'p.provin_codigo')
                ->select(
                    'pr.parroq_codigo',
                    'pr.parroq_nombre',
                    'pr.parroq_canton_codigo',
                    'c.canton_nombre',
                    'p.provin_nombre'
                )
                ->orderBy('p.provin_nombre')
                ->orderBy('c.canton_nombre')
                ->orderBy('pr.parroq_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $parroquias,
                'message' => 'Parroquias obtenidas correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo parroquias: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener parroquias',
                'data' => []
            ], 500);
        }
    }

    public function getByCanton($cantonId)
    {
        try {
            $parroquias = DB::table('gaf_parroq')
                ->where('parroq_canton_codigo', $cantonId)
                ->select(
                    'parroq_codigo as value',
                    'parroq_nombre as label'
                )
                ->orderBy('parroq_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $parroquias,
                'message' => 'Parroquias por cantón obtenidas correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo parroquias por cantón: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener parroquias por cantón',
                'data' => []
            ], 500);
        }
    }
}