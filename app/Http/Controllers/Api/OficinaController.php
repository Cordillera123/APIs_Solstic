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
    private function filtrarCamposOpcionales($data)
    {
        $camposOpcionales = [
            'oficin_fechacierre',
            'oficin_codrescierre',
            'oficin_fecharescierre',
            'oficin_eregis_codigo'
        ];

        foreach ($camposOpcionales as $campo) {
            if (empty($data[$campo])) {
                unset($data[$campo]);
            }
        }

        return $data;
    }

    public function store(Request $request)
    {
        // ✅ VALIDACIONES CORREGIDAS
        $validator = Validator::make($request->all(), [
            'oficin_nombre' => 'required|string|max:60',
            'oficin_instit_codigo' => 'required|integer|exists:gaf_instit,instit_codigo',
            'oficin_tofici_codigo' => 'required|integer|exists:gaf_tofici,tofici_codigo',
            'oficin_parroq_codigo' => 'required|integer|exists:gaf_parroq,parroq_codigo',
            'oficin_direccion' => 'required|string|max:80',
            'oficin_telefono' => 'required|string|max:30',
            'oficin_diremail' => 'required|email|max:120',
            'oficin_rucoficina' => 'required|string|size:13|unique:gaf_oficin,oficin_rucoficina|regex:/^[0-9]{13}$/',
            'oficin_codocntrl' => 'nullable|string|max:20',
            'oficin_ctractual' => 'required|integer|in:0,1',
            'oficin_eregis_codigo' => 'nullable|integer|exists:gaf_eregis,eregis_codigo',
            'oficin_codresapertura' => 'nullable|string|max:20',
            'oficin_fechaapertura' => 'nullable|date',
            'oficin_fechacierre' => 'nullable|date|after:oficin_fechaapertura',
            'oficin_codrescierre' => 'nullable|string|max:20',
            'oficin_fecharescierre' => 'nullable|date'
        ], [
            // Mensajes personalizados
            'oficin_nombre.required' => 'El nombre de la oficina es requerido',
            'oficin_nombre.max' => 'El nombre no puede exceder 60 caracteres',
            'oficin_rucoficina.size' => 'El RUC debe tener exactamente 13 dígitos',
            'oficin_rucoficina.unique' => 'Este RUC ya está registrado en otra oficina',
            'oficin_rucoficina.regex' => 'El RUC debe contener solo números',
            'oficin_diremail.email' => 'El formato del email es inválido',
            'oficin_instit_codigo.exists' => 'La institución seleccionada no existe',
            'oficin_tofici_codigo.exists' => 'El tipo de oficina seleccionado no existe',
            'oficin_parroq_codigo.exists' => 'La parroquia seleccionada no existe'
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

            // ✅ FILTRAR CAMPOS OPCIONALES VACÍOS
            $oficinaData = $this->filtrarCamposOpcionales($request->all());

            // Asegurar valores por defecto para campos requeridos
            $oficinaData['oficin_codocntrl'] = $oficinaData['oficin_codocntrl'] ?: 'AUTO-' . time();
            $oficinaData['oficin_fechaapertura'] = $oficinaData['oficin_fechaapertura'] ?: Carbon::now()->format('Y-m-d');
            $oficinaData['oficin_codresapertura'] = $oficinaData['oficin_codresapertura'] ?: 'PENDIENTE';

            Log::info("🏢 Creando oficina con datos filtrados:", [
                'nombre' => $oficinaData['oficin_nombre'],
                'ruc' => $oficinaData['oficin_rucoficina'],
                'campos_incluidos' => array_keys($oficinaData)
            ]);

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
            Log::error("❌ Stack trace: " . $e->getTraceAsString());

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
        }
    }

    /**
     * Update the specified resource in storage.
     * PUT /api/oficinas/{id}
     */
    public function update(Request $request, $id)
    {
        // ✅ VALIDACIONES CORREGIDAS PARA UPDATE
        $validator = Validator::make($request->all(), [
            'oficin_nombre' => 'required|string|max:60',
            'oficin_instit_codigo' => 'required|integer|exists:gaf_instit,instit_codigo',
            'oficin_tofici_codigo' => 'required|integer|exists:gaf_tofici,tofici_codigo',
            'oficin_parroq_codigo' => 'required|integer|exists:gaf_parroq,parroq_codigo',
            'oficin_direccion' => 'required|string|max:80',
            'oficin_telefono' => 'required|string|max:30',
            'oficin_diremail' => 'required|email|max:120',
            'oficin_rucoficina' => 'required|string|size:13|unique:gaf_oficin,oficin_rucoficina,' . $id . ',oficin_codigo|regex:/^[0-9]{13}$/',
            'oficin_codocntrl' => 'nullable|string|max:20',
            'oficin_ctractual' => 'required|integer|in:0,1',
            'oficin_eregis_codigo' => 'nullable|integer|exists:gaf_eregis,eregis_codigo',
            'oficin_codresapertura' => 'nullable|string|max:20',
            'oficin_fechaapertura' => 'nullable|date',
            'oficin_fechacierre' => 'nullable|date|after:oficin_fechaapertura',
            'oficin_codrescierre' => 'nullable|string|max:20',
            'oficin_fecharescierre' => 'nullable|date'
        ], [
            // Mensajes personalizados
            'oficin_nombre.required' => 'El nombre de la oficina es requerido',
            'oficin_nombre.max' => 'El nombre no puede exceder 60 caracteres',
            'oficin_rucoficina.size' => 'El RUC debe tener exactamente 13 dígitos',
            'oficin_rucoficina.unique' => 'Este RUC ya está registrado en otra oficina',
            'oficin_rucoficina.regex' => 'El RUC debe contener solo números',
            'oficin_diremail.email' => 'El formato del email es inválido',
            'oficin_instit_codigo.exists' => 'La institución seleccionada no existe',
            'oficin_tofici_codigo.exists' => 'El tipo de oficina seleccionado no existe',
            'oficin_parroq_codigo.exists' => 'La parroquia seleccionada no existe'
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

            // ✅ FILTRAR CAMPOS OPCIONALES VACÍOS
            $updateData = $this->filtrarCamposOpcionales($request->all());

            // Mantener valores existentes si no se proporcionan nuevos (solo para campos requeridos)
            if (empty($updateData['oficin_codocntrl'])) {
                $updateData['oficin_codocntrl'] = $oficina->oficin_codocntrl;
            }
            if (empty($updateData['oficin_fechaapertura'])) {
                $updateData['oficin_fechaapertura'] = $oficina->oficin_fechaapertura;
            }
            if (empty($updateData['oficin_codresapertura'])) {
                $updateData['oficin_codresapertura'] = $oficina->oficin_codresapertura;
            }

            Log::info("🔄 Actualizando oficina con datos filtrados:", [
                'id' => $oficina->oficin_codigo,
                'nombre_anterior' => $oficina->oficin_nombre,
                'nombre_nuevo' => $updateData['oficin_nombre'],
                'campos_incluidos' => array_keys($updateData)
            ]);

            $oficina->update($updateData);

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
            Log::error("❌ Stack trace: " . $e->getTraceAsString());

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
     * GET /api/oficinas/listar/simple
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
    public function stats()
    {
        try {
            $stats = [
                'total_oficinas' => DB::table('gaf_oficin')->count(),
                'oficinas_activas' => DB::table('gaf_oficin')->where('oficin_ctractual', 1)->count(),
                'oficinas_inactivas' => DB::table('gaf_oficin')->where('oficin_ctractual', 0)->count(),
                'oficinas_con_usuarios' => DB::table('gaf_oficin')
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('tbl_usu')
                            ->whereRaw('tbl_usu.oficin_codigo = gaf_oficin.oficin_codigo');
                    })
                    ->count(),
                'total_usuarios_asignados' => DB::table('tbl_usu')
                    ->whereNotNull('oficin_codigo')
                    ->count(),
                'usuarios_activos_asignados' => DB::table('tbl_usu')
                    ->whereNotNull('oficin_codigo')
                    ->where('usu_deshabilitado', false)
                    ->count(),
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Estadísticas obtenidas correctamente',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo estadísticas: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas',
                'data' => null
            ], 500);
        }
    }

    /**
     * Filtrar oficinas por institución
     * GET /api/oficinas/by-institucion/{institucionId}
     */
    public function byInstitucion($institucionId, Request $request)
    {
        try {
            $request->merge(['instit_codigo' => $institucionId]);
            return $this->index($request);
        } catch (\Exception $e) {
            Log::error("❌ Error filtrando por institución: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al filtrar por institución',
                'data' => null
            ], 500);
        }
    }

    /**
     * Filtrar oficinas por tipo
     * GET /api/oficinas/by-tipo/{tipoId}
     */
    public function byTipo($tipoId, Request $request)
    {
        try {
            $request->merge(['tofici_codigo' => $tipoId]);
            return $this->index($request);
        } catch (\Exception $e) {
            Log::error("❌ Error filtrando por tipo: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al filtrar por tipo',
                'data' => null
            ], 500);
        }
    }

    /**
     * Filtrar oficinas por parroquia
     * GET /api/oficinas/by-parroquia/{parroquiaId}
     */
    public function byParroquia($parroquiaId, Request $request)
    {
        try {
            $request->merge(['parroq_codigo' => $parroquiaId]);
            return $this->index($request);
        } catch (\Exception $e) {
            Log::error("❌ Error filtrando por parroquia: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al filtrar por parroquia',
                'data' => null
            ], 500);
        }
    }

    /**
     * Búsqueda avanzada de oficinas
     * POST /api/oficinas/search
     */
    public function search(Request $request)
    {
        try {
            Log::info("🔍 Búsqueda avanzada de oficinas:", $request->all());
            return $this->index($request);
        } catch (\Exception $e) {
            Log::error("❌ Error en búsqueda avanzada: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error en búsqueda avanzada',
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener oficinas activas solamente
     * GET /api/oficinas/activas
     */
    public function activas(Request $request)
    {
        try {
            $request->merge(['solo_activas' => true]);
            return $this->index($request);
        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo oficinas activas: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener oficinas activas',
                'data' => null
            ], 500);
        }
    }
}
