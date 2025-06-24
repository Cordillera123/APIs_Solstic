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
                ->select('instit_codigo', 'instit_nombre', 'instit_activo')
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
                ->where('instit_activo', true)
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

// ===== app/Http/Controllers/Api/ProvinciaController.php =====
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProvinciaController extends Controller
{
    public function index()
    {
        try {
            $provincias = DB::table('gaf_provin')
                ->select('provin_codigo', 'provin_nombre')
                ->orderBy('provin_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $provincias,
                'message' => 'Provincias obtenidas correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo provincias: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener provincias',
                'data' => []
            ], 500);
        }
    }

    public function listar()
    {
        try {
            $provincias = DB::table('gaf_provin')
                ->select(
                    'provin_codigo as value',
                    'provin_nombre as label'
                )
                ->orderBy('provin_nombre')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $provincias,
                'message' => 'Lista de provincias obtenida correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error en provincias.listar: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener lista de provincias',
                'data' => []
            ], 500);
        }
    }
}



