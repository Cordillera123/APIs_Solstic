<?php
// ===== app/Http/Controllers/Api/InstitucionController.php =====
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InstitucionController extends Controller
{
    public function index()
    {
        try {
            $instituciones = DB::table('gaf_instit')
                ->select('instit_codigo', 'instit_nombre')
                ->orderBy('instit_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $instituciones,
                'message' => 'Instituciones obtenidas correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo instituciones: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener instituciones',
                'data' => []
            ], 500);
        }
    }

    public function listar()
    {
        try {
            $instituciones = DB::table('gaf_instit')
                ->select(
                    'instit_codigo as value',
                    'instit_nombre as label'
                )
                ->orderBy('instit_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $instituciones,
                'message' => 'Lista de instituciones obtenida correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error en instituciones.listar: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener lista de instituciones',
                'data' => []
            ], 500);
        }
    }
}
