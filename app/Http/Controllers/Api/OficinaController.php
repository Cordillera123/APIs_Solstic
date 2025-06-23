<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Oficina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OficinaController extends Controller
{
    /**
     * Display a listing of the resource.
     * GET /api/oficinas
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $institucionId = $request->get('instit_codigo', '');
            $tipoOficinaId = $request->get('tofici_codigo', '');
            $parroquiaId = $request->get('parroq_codigo', '');
            $soloActivas = $request->boolean('solo_activas', false);

            Log::info("🔍 Parámetros recibidos en index oficinas:", [
                'per_page' => $perPage,
                'search' => $search,
                'instit_codigo' => $institucionId,
                'tofici_codigo' => $tipoOficinaId,
                'parroq_codigo' => $parroquiaId,
                'solo_activas' => $soloActivas
            ]);

            $query = DB::table('gaf_oficin')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
                ->leftJoin('gaf_parroq', 'gaf_oficin.oficin_parroq_codigo', '=', 'gaf_parroq.parroq_codigo')
                ->leftJoin('gaf_canton', 'gaf_parroq.parroq_canton_codigo', '=', 'gaf_canton.canton_codigo')
                ->leftJoin('gaf_provin', 'gaf_canton.canton_provin_codigo', '=', 'gaf_provin.provin_codigo')
                ->leftJoin('gaf_eregis', 'gaf_oficin.oficin_eregis_codigo', '=', 'gaf_eregis.eregis_codigo')
                ->select(
                    'gaf_oficin.*',
                    'gaf_instit.instit_nombre',
                    'gaf_tofici.tofici_descripcion',
                    'gaf_parroq.parroq_nombre',
                    'gaf_canton.canton_nombre',
                    'gaf_provin.provin_nombre',
                    'gaf_eregis.eregis_descripcion',
                    DB::raw('(SELECT COUNT(*) FROM tbl_usu WHERE tbl_usu.oficin_codigo = gaf_oficin.oficin_codigo AND usu_deshabilitado = false) as cantidad_usuarios_activos'),
                    DB::raw('(SELECT COUNT(*) FROM tbl_usu WHERE tbl_usu.oficin_codigo = gaf_oficin.oficin_codigo) as cantidad_usuarios_total')
                );

            // Filtro por estado activo
            if ($soloActivas) {
                $query->where('gaf_oficin.oficin_ctractual', 1);
                Log::info("🔍 Filtro aplicado: Solo oficinas activas");
            }

            // Filtro de búsqueda
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('gaf_oficin.oficin_nombre', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_oficin.oficin_direccion', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_oficin.oficin_diremail', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_oficin.oficin_telefono', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_oficin.oficin_rucoficina', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_instit.instit_nombre', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_tofici.tofici_descripcion', 'ILIKE', "%{$search}%");
                });
            }

            // Filtros específicos
            if (!empty($institucionId)) {
                $query->where('gaf_oficin.oficin_instit_codigo', $institucionId);
            }

            if (!empty($tipoOficinaId)) {
                $query->where('gaf_oficin.oficin_tofici_codigo', $tipoOficinaId);
            }

            if (!empty($parroquiaId)) {
                $query->where('gaf_oficin.oficin_parroq_codigo', $parroquiaId);
            }

            $oficinas = $query->orderBy('gaf_oficin.oficin_nombre', 'asc')
                ->paginate($perPage);

            Log::info("✅ Oficinas obtenidas:", [
                'total' => $oficinas->total(),
                'current_page' => $oficinas->currentPage(),
                'per_page' => $oficinas->perPage()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Oficinas obtenidas correctamente',
                'data' => $oficinas,
                'debug_info' => [
                    'total_oficinas' => $oficinas->total(),
                    'pagina_actual' => $oficinas->currentPage(),
                    'per_page' => $oficinas->perPage(),
                    'solo_activas' => $soloActivas,
                    'filtros_aplicados' => [
                        'search' => $search,
                        'instit_codigo' => $institucionId,
                        'tofici_codigo' => $tipoOficinaId,
                        'parroq_codigo' => $parroquiaId
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error en index oficinas: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener oficinas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     * POST /api/oficinas
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oficin_nombre' => 'required|string|max:60',
            'oficin_instit_codigo' => 'required|integer|exists:gaf_instit,instit_codigo',
            'oficin_tofici_codigo' => 'required|integer|exists:gaf_tofici,tofici_codigo',
            'oficin_parroq_codigo' => 'required|integer|exists:gaf_parroq,parroq_codigo',
            'oficin_direccion' => 'required|string|max:80',
            'oficin_telefono' => 'required|string|max:30',
            'oficin_diremail' => 'required|email|max:120',
            'oficin_codocntrl' => 'required|string|max:20',
            'oficin_ctractual' => 'required|integer|in:0,1',
            'oficin_eregis_codigo' => 'nullable|integer|exists:gaf_eregis,eregis_codigo',
            'oficin_rucoficina' => 'required|string|max:20|unique:gaf_oficin,oficin_rucoficina',
            'oficin_codresapertura' => 'required|string|max:20',
            'oficin_fechaapertura' => 'required|date',
            'oficin_fechacierre' => 'nullable|date|after:oficin_fechaapertura',
            'oficin_codrescierre' => 'nullable|date',
            'oficin_fecharescierre' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            $oficinaData = $request->all();

            Log::info("🏢 Creando oficina:", ['nombre' => $oficinaData['oficin_nombre']]);

            $oficina = Oficina::create($oficinaData);

            Log::info("📥 Oficina creada con éxito:", [
                'id' => $oficina->oficin_codigo,
                'nombre' => $oficina->oficin_nombre
            ]);

            // Obtener la oficina creada con relaciones
            $oficinaCompleta = $this->getOficinaCompleta($oficina->oficin_codigo);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Oficina creada exitosamente',
                'data' => $oficinaCompleta
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error creando oficina: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear oficina: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     * GET /api/oficinas/{id}
     */
    public function show($id)
    {
        try {
            $oficina = $this->getOficinaCompleta($id);

            if (!$oficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina no encontrada',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Oficina obtenida correctamente',
                'data' => $oficina
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en show oficina: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener oficina: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     * PUT /api/oficinas/{id}
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'oficin_nombre' => 'required|string|max:60',
            'oficin_instit_codigo' => 'required|integer|exists:gaf_instit,instit_codigo',
            'oficin_tofici_codigo' => 'required|integer|exists:gaf_tofici,tofici_codigo',
            'oficin_parroq_codigo' => 'required|integer|exists:gaf_parroq,parroq_codigo',
            'oficin_direccion' => 'required|string|max:80',
            'oficin_telefono' => 'required|string|max:30',
            'oficin_diremail' => 'required|email|max:120',
            'oficin_codocntrl' => 'required|string|max:20',
            'oficin_ctractual' => 'required|integer|in:0,1',
            'oficin_eregis_codigo' => 'nullable|integer|exists:gaf_eregis,eregis_codigo',
            'oficin_rucoficina' => 'required|string|max:20|unique:gaf_oficin,oficin_rucoficina,' . $id . ',oficin_codigo',
            'oficin_codresapertura' => 'required|string|max:20',
            'oficin_fechaapertura' => 'required|date',
            'oficin_fechacierre' => 'nullable|date|after:oficin_fechaapertura',
            'oficin_codrescierre' => 'nullable|date',
            'oficin_fecharescierre' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            $oficina = Oficina::find($id);

            if (!$oficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina no encontrada',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            $oficina->update($request->all());

            Log::info("✅ Oficina actualizada:", [
                'id' => $oficina->oficin_codigo,
                'nombre' => $oficina->oficin_nombre
            ]);

            // Obtener la oficina actualizada con relaciones
            $oficinaCompleta = $this->getOficinaCompleta($oficina->oficin_codigo);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Oficina actualizada exitosamente',
                'data' => $oficinaCompleta
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error actualizando oficina: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar oficina: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     * DELETE /api/oficinas/{id}
     */
    
public function destroy($id)
{
    try {
        Log::info("🗑️ Intentando eliminar oficina ID: {$id}");
        
        $oficina = Oficina::find($id);

        if (!$oficina) {
            Log::warning("❌ Oficina no encontrada: ID {$id}");
            return response()->json([
                'status' => 'error',
                'message' => 'Oficina no encontrada',
                'data' => null
            ], 404);
        }

        // Verificar si tiene usuarios asignados
        $cantidadUsuarios = DB::table('tbl_usu')
            ->where('oficin_codigo', $id)
            ->count();
            
        Log::info("📊 Usuarios encontrados en oficina {$id}: {$cantidadUsuarios}");

        if ($cantidadUsuarios > 0) {
            Log::warning("⚠️ No se puede eliminar oficina {$id}: tiene {$cantidadUsuarios} usuarios");
            return response()->json([
                'status' => 'error',
                'message' => "No se puede eliminar la oficina porque tiene {$cantidadUsuarios} usuario(s) asignado(s)",
                'data' => [
                    'oficin_codigo' => $oficina->oficin_codigo,
                    'oficin_nombre' => $oficina->oficin_nombre,
                    'cantidad_usuarios' => $cantidadUsuarios
                ]
            ], 422);
        }

        DB::beginTransaction();

        $oficinaInfo = [
            'oficin_codigo' => $oficina->oficin_codigo,
            'oficin_nombre' => $oficina->oficin_nombre,
            'oficin_direccion' => $oficina->oficin_direccion
        ];

        $oficina->delete();

        DB::commit();

        Log::info("✅ Oficina eliminada correctamente: {$oficina->oficin_codigo}");

        return response()->json([
            'status' => 'success',
            'message' => 'Oficina eliminada exitosamente',
            'data' => $oficinaInfo
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        Log::error("❌ Error de base de datos eliminando oficina: " . $e->getMessage());
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error de base de datos al eliminar oficina',
            'data' => [
                'error_code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null
            ]
        ], 500);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error general eliminando oficina: " . $e->getMessage());
        Log::error("❌ Trace: " . $e->getTraceAsString());

        return response()->json([
            'status' => 'error',
            'message' => 'Error interno del servidor',
            'data' => [
                'error_type' => get_class($e),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ]
        ], 500);
    }
}

    /**
     * Obtener lista simple de oficinas para select/dropdown
     * GET /api/oficinas/listar
     */
    public function listar(Request $request)
    {
        try {
            $soloActivas = $request->boolean('solo_activas', true);
            $search = $request->get('search', '');
            $institucionId = $request->get('instit_codigo', '');
            $tipoOficinaId = $request->get('tofici_codigo', '');

            $query = DB::table('gaf_oficin')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
                ->select(
                    'gaf_oficin.oficin_codigo as value',
                    'gaf_oficin.oficin_nombre as label',
                    'gaf_oficin.oficin_direccion',
                    'gaf_instit.instit_nombre',
                    'gaf_tofici.tofici_descripcion',
                    'gaf_oficin.oficin_ctractual',
                    DB::raw("CONCAT(gaf_tofici.tofici_descripcion, ' - ', gaf_oficin.oficin_nombre) as nombre_completo")
                );

            if ($soloActivas) {
                $query->where('gaf_oficin.oficin_ctractual', 1);
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('gaf_oficin.oficin_nombre', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_instit.instit_nombre', 'ILIKE', "%{$search}%")
                        ->orWhere('gaf_tofici.tofici_descripcion', 'ILIKE', "%{$search}%");
                });
            }

            if (!empty($institucionId)) {
                $query->where('gaf_oficin.oficin_instit_codigo', $institucionId);
            }

            if (!empty($tipoOficinaId)) {
                $query->where('gaf_oficin.oficin_tofici_codigo', $tipoOficinaId);
            }

            $oficinas = $query->orderBy('gaf_oficin.oficin_nombre', 'asc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Lista de oficinas obtenida correctamente',
                'data' => $oficinas
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error en listar oficinas: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener lista de oficinas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
 * Obtener usuarios de una oficina específica
 * GET /api/oficinas/{id}/usuarios
 */
public function usuarios($id, Request $request)
{
    try {
        Log::info("👥 Buscando usuarios de oficina ID: {$id}");
        
        // Verificar que la oficina existe
        $oficina = DB::table('gaf_oficin')
            ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
            ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
            ->where('gaf_oficin.oficin_codigo', $id)
            ->select(
                'gaf_oficin.oficin_codigo',
                'gaf_oficin.oficin_nombre',
                'gaf_oficin.oficin_direccion',
                'gaf_oficin.oficin_telefono',
                'gaf_oficin.oficin_diremail',
                'gaf_oficin.oficin_ctractual',
                'gaf_instit.instit_nombre',
                'gaf_tofici.tofici_descripcion as tipo_oficina'
            )
            ->first();

        if (!$oficina) {
            Log::warning("❌ Oficina no encontrada: ID {$id}");
            return response()->json([
                'status' => 'error',
                'message' => 'Oficina no encontrada',
                'data' => null
            ], 404);
        }

        $perPage = $request->get('per_page', 15);
        $incluirDeshabilitados = $request->boolean('incluir_deshabilitados', false);
        $search = $request->get('search', '');

        // Consulta directa a usuarios de la oficina
        $query = DB::table('tbl_usu')
            ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
            ->where('tbl_usu.oficin_codigo', $id)
            ->select([
                'tbl_usu.usu_id', 
                'tbl_usu.usu_nom', 
                'tbl_usu.usu_nom2', 
                'tbl_usu.usu_ape', 
                'tbl_usu.usu_ape2', 
                'tbl_usu.usu_cor', 
                'tbl_usu.usu_ced', 
                'tbl_usu.usu_tel', 
                'tbl_usu.per_id', 
                'tbl_usu.est_id', 
                'tbl_usu.usu_ultimo_acceso', 
                'tbl_usu.usu_deshabilitado',
                'tbl_usu.usu_fecha_registro',
                'tbl_per.per_nom as perfil',
                'tbl_est.est_nom as estado',
                DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
            ]);

        // Filtro por usuarios deshabilitados
        if (!$incluirDeshabilitados) {
            $query->where('tbl_usu.usu_deshabilitado', false);
            Log::info("🔍 Filtro aplicado: Solo usuarios habilitados");
        }

        // Filtro de búsqueda
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('tbl_usu.usu_nom', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_usu.usu_ape', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_usu.usu_cor', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_usu.usu_ced', 'ILIKE', "%{$search}%");
            });
            Log::info("🔍 Filtro de búsqueda aplicado: {$search}");
        }

        $usuarios = $query->orderBy('tbl_usu.usu_nom', 'asc')
            ->paginate($perPage);

        // Calcular resumen
        $resumen = [
            'total_usuarios' => $usuarios->total(),
            'usuarios_activos' => DB::table('tbl_usu')
                ->where('oficin_codigo', $id)
                ->where('usu_deshabilitado', false)
                ->count(),
            'usuarios_deshabilitados' => DB::table('tbl_usu')
                ->where('oficin_codigo', $id)
                ->where('usu_deshabilitado', true)
                ->count()
        ];

        Log::info("✅ Usuarios obtenidos de oficina {$id}:", [
            'total' => $usuarios->total(),
            'pagina_actual' => $usuarios->currentPage(),
            'activos' => $resumen['usuarios_activos'],
            'deshabilitados' => $resumen['usuarios_deshabilitados']
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Usuarios de la oficina obtenidos correctamente',
            'data' => [
                'oficina' => [
                    'oficin_codigo' => $oficina->oficin_codigo,
                    'oficin_nombre' => $oficina->oficin_nombre,
                    'oficin_direccion' => $oficina->oficin_direccion,
                    'oficin_telefono' => $oficina->oficin_telefono,
                    'oficin_diremail' => $oficina->oficin_diremail,
                    'activa' => $oficina->oficin_ctractual == 1,
                    'institucion' => $oficina->instit_nombre,
                    'tipo_oficina' => $oficina->tipo_oficina
                ],
                'usuarios' => $usuarios,
                'resumen' => $resumen
            ]
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        Log::error("❌ Error de base de datos obteniendo usuarios de oficina: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error de base de datos al obtener usuarios',
            'data' => [
                'error_code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null
            ]
        ], 500);
        
    } catch (\Exception $e) {
        Log::error("❌ Error general obteniendo usuarios de oficina: " . $e->getMessage());
        Log::error("❌ Trace: " . $e->getTraceAsString());
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error interno del servidor',
            'data' => [
                'error_type' => get_class($e),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ]
        ], 500);
    }
}
    /**
     * Método privado para obtener oficina completa con relaciones
     */
    private function getOficinaCompleta($id)
    {
        return DB::table('gaf_oficin')
            ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
            ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
            ->leftJoin('gaf_parroq', 'gaf_oficin.oficin_parroq_codigo', '=', 'gaf_parroq.parroq_codigo')
            ->leftJoin('gaf_canton', 'gaf_parroq.parroq_canton_codigo', '=', 'gaf_canton.canton_codigo')
            ->leftJoin('gaf_provin', 'gaf_canton.canton_provin_codigo', '=', 'gaf_provin.provin_codigo')
            ->leftJoin('gaf_eregis', 'gaf_oficin.oficin_eregis_codigo', '=', 'gaf_eregis.eregis_codigo')
            ->where('gaf_oficin.oficin_codigo', $id)
            ->select(
                'gaf_oficin.*',
                'gaf_instit.instit_nombre',
                'gaf_tofici.tofici_descripcion',
                'gaf_parroq.parroq_nombre',
                'gaf_canton.canton_nombre',
                'gaf_provin.provin_nombre',
                'gaf_eregis.eregis_descripcion',
                DB::raw('(SELECT COUNT(*) FROM tbl_usu WHERE tbl_usu.oficin_codigo = gaf_oficin.oficin_codigo AND usu_deshabilitado = false) as cantidad_usuarios_activos'),
                DB::raw('(SELECT COUNT(*) FROM tbl_usu WHERE tbl_usu.oficin_codigo = gaf_oficin.oficin_codigo) as cantidad_usuarios_total'),
                DB::raw("CONCAT(gaf_oficin.oficin_direccion, ', ', COALESCE(gaf_parroq.parroq_nombre, ''), ', ', COALESCE(gaf_canton.canton_nombre, ''), ', ', COALESCE(gaf_provin.provin_nombre, '')) as direccion_completa"),
                DB::raw("CONCAT(gaf_tofici.tofici_descripcion, ' - ', gaf_oficin.oficin_nombre) as nombre_completo")
            )
            ->first();
    }
}