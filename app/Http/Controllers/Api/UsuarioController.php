<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\PerfilVisibilidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    
    /**
    * Listar Usuarios con Empresas
     */
public function index(Request $request)
{
    try {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search', '');
        $perfilId = $request->get('per_id', ''); 
        $estadoId = $request->get('estado_id', '');
        $oficinaCodigo = $request->get('oficina_codigo', '');
        $sinOficina = $request->boolean('sin_oficina', false);
        $incluirDeshabilitados = $request->boolean('incluir_deshabilitados', false);

        $query = DB::table('tbl_usu')
            ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
            ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
            ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
            ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
            ->leftJoin('tbl_usu as creador', 'tbl_usu.usu_creado_por', '=', 'creador.usu_id')
            ->select(
                'tbl_usu.usu_id',
                'tbl_usu.usu_nom',
                'tbl_usu.usu_nom2',
                'tbl_usu.usu_ape',
                'tbl_usu.usu_ape2',
                'tbl_usu.usu_cor',
                'tbl_usu.usu_ced',
                'tbl_usu.usu_tel',
                'tbl_usu.usu_dir',
                'tbl_usu.usu_descripcion',
                'tbl_usu.usu_fecha_nacimiento',
                'tbl_usu.usu_fecha_registro',
                'tbl_usu.usu_ultimo_acceso',
                'tbl_usu.usu_intentos_fallidos',
                'tbl_usu.usu_bloqueado_hasta',
                'tbl_usu.usu_deshabilitado',
                'tbl_per.per_nom as perfil',
                'tbl_est.est_nom as estado',
                'tbl_usu.per_id',
                'tbl_usu.est_id',
                'creador.usu_nom as creado_por_nombre',
                'tbl_usu.usu_cre',
                'tbl_usu.usu_edi',
                'tbl_usu.oficin_codigo',
                'gaf_oficin.oficin_nombre as oficina_nombre',
                'gaf_tofici.tofici_descripcion as tipo_oficina',
                'gaf_instit.instit_nombre as institucion',
                DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
            );

        // ✅ FILTRO: Excluir al usuario autenticado del listado
        if (Auth::check() && Auth::user()) {
            $currentUser = Auth::user();
            $query->where('tbl_usu.usu_id', '!=', $currentUser->usu_id);
            Log::info("🔒 Ocultando usuario actual del listado: {$currentUser->usu_id} - {$currentUser->usu_cor}");

            // ✅ NUEVO: Filtrar por perfiles que el usuario autenticado puede ver
            $perfilesVisibles = $this->getPerfilesVisiblesPorUsuario($currentUser->usu_id);
            
            if ($perfilesVisibles->isNotEmpty()) {
                $perfilesIds = $perfilesVisibles->pluck('per_id')->toArray();
                $query->whereIn('tbl_usu.per_id', $perfilesIds);
                Log::info("🔒 Filtro de perfiles aplicado para usuario {$currentUser->usu_id}: " . implode(',', $perfilesIds));
            } else {
                // Si no tiene permisos para ver ningún perfil, no mostrar usuarios
                $query->whereRaw('1 = 0'); // Condición que nunca se cumple
                Log::info("🔒 Usuario {$currentUser->usu_id} sin permisos para ver perfiles");
            }
        }

        // Filtros existentes
        if (!$incluirDeshabilitados) {
            $query->where('tbl_usu.usu_deshabilitado', false);
        }

        if (!empty($oficinaCodigo)) {
            $query->where('tbl_usu.oficin_codigo', $oficinaCodigo);
        }

        if ($sinOficina) {
            $query->whereNull('tbl_usu.oficin_codigo');
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('tbl_usu.usu_nom', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_usu.usu_ape', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_usu.usu_cor', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_usu.usu_ced', 'ILIKE', "%{$search}%")
                    ->orWhere('tbl_per.per_nom', 'ILIKE', "%{$search}%")
                    ->orWhere('gaf_oficin.oficin_nombre', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($perfilId)) {
            $query->where('tbl_usu.per_id', $perfilId);
        }

        if (!empty($estadoId)) {
            $query->where('tbl_usu.est_id', $estadoId);
        }

        $usuarios = $query->orderBy('tbl_usu.usu_fecha_registro', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Usuarios obtenidos correctamente',
            'data' => $usuarios
        ]);

    } catch (\Exception $e) {
        Log::error("❌ Error en index usuarios: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener usuarios: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}
/**
 * Obtener perfiles para el filtro (solo los que el usuario puede ver)
 */
public function getPerfilesParaFiltro(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
                'data' => []
            ], 401);
        }

        $perfilesPermitidos = $this->getPerfilesVisiblesPorUsuario($user->usu_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Perfiles para filtro obtenidos correctamente',
            'data' => $perfilesPermitidos
        ]);
    } catch (\Exception $e) {
        Log::error("❌ Error obteniendo perfiles para filtro: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener perfiles para filtro',
            'data' => []
        ], 500);
    }
}
/**
 * Obtener perfiles visibles para un usuario específico
 */
public function getPerfilesVisiblesUsuario($usuarioId)
{
    try {
        $usuario = Usuario::find($usuarioId);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => []
            ], 404);
        }

        $perfilesVisibles = DB::table('tbl_per')
            ->join('tbl_perm_perfil_visibilidad', 'tbl_per.per_id', '=', 'tbl_perm_perfil_visibilidad.per_id_visible')
            ->where('tbl_perm_perfil_visibilidad.usu_id', $usuarioId)
            ->where('tbl_perm_perfil_visibilidad.perm_per_vis_activo', true)
            ->where('tbl_per.per_activo', true)
            ->select(
                'tbl_per.per_id', 
                'tbl_per.per_nom',
                'tbl_per.per_descripcion',
                'tbl_perm_perfil_visibilidad.perm_per_vis_cre as asignado_fecha'
            )
            ->orderBy('tbl_per.per_nom')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Perfiles visibles obtenidos correctamente',
            'data' => $perfilesVisibles
        ]);

    } catch (\Exception $e) {
        Log::error("❌ Error obteniendo perfiles visibles del usuario {$usuarioId}: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener perfiles visibles',
            'data' => []
        ], 500);
    }
}
/**
 * Obtener estadísticas de visibilidad de perfiles
 */
public function getEstadisticasVisibilidad()
{
    try {
        Log::info("📊 Obteniendo estadísticas de visibilidad de perfiles");

        $stats = [
            'total_usuarios' => DB::table('tbl_usu')->where('usu_deshabilitado', false)->count(),
            
            'usuarios_con_visibilidad_configurada' => DB::table('tbl_perm_perfil_visibilidad')
                ->where('perm_per_vis_activo', true)
                ->distinct('usu_id')
                ->count('usu_id'),
                
            'total_asignaciones_visibilidad' => DB::table('tbl_perm_perfil_visibilidad')
                ->where('perm_per_vis_activo', true)
                ->count(),
                
            'perfiles_mas_visibles' => DB::table('tbl_per')
                ->join('tbl_perm_perfil_visibilidad', 'tbl_per.per_id', '=', 'tbl_perm_perfil_visibilidad.per_id_visible')
                ->where('tbl_perm_perfil_visibilidad.perm_per_vis_activo', true)
                ->where('tbl_per.per_activo', true)
                ->select(
                    'tbl_per.per_id',
                    'tbl_per.per_nom',
                    DB::raw('COUNT(*) as usuarios_pueden_ver')
                )
                ->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                ->orderBy('usuarios_pueden_ver', 'desc')
                ->limit(10)
                ->get(),
                
            'usuarios_sin_visibilidad' => DB::table('tbl_usu')
                ->leftJoin('tbl_perm_perfil_visibilidad', function($join) {
                    $join->on('tbl_usu.usu_id', '=', 'tbl_perm_perfil_visibilidad.usu_id')
                         ->where('tbl_perm_perfil_visibilidad.perm_per_vis_activo', '=', true);
                })
                ->where('tbl_usu.usu_deshabilitado', false)
                ->whereNull('tbl_perm_perfil_visibilidad.usu_id')
                ->count(),
                
            'promedio_perfiles_por_usuario' => round(
                DB::table('tbl_perm_perfil_visibilidad')
                    ->where('perm_per_vis_activo', true)
                    ->count() / 
                max(1, DB::table('tbl_perm_perfil_visibilidad')
                    ->where('perm_per_vis_activo', true)
                    ->distinct('usu_id')
                    ->count('usu_id')), 2
            )
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Estadísticas de visibilidad obtenidas correctamente',
            'data' => $stats
        ]);

    } catch (\Exception $e) {
        Log::error("❌ Error obteniendo estadísticas de visibilidad: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener estadísticas de visibilidad',
            'data' => [
                'total_usuarios' => 0,
                'usuarios_con_visibilidad_configurada' => 0,
                'total_asignaciones_visibilidad' => 0,
                'perfiles_mas_visibles' => [],
                'usuarios_sin_visibilidad' => 0,
                'promedio_perfiles_por_usuario' => 0
            ]
        ], 500);
    }
}

    /*
    *Agregar metodos para listar y mostar informacion de usuarios de la oficina a la que
    *pertenece el usuario autenticado y la institucion a la que pertenece el usuario autenticado
    */

    /**
     * Obtener información del usuario logueado
     * GET /api/usuario/me
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user(); // Usuario autenticado
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                    'data' => null
                ], 401);
            }

            Log::info("📋 Obteniendo información del usuario logueado: {$user->usu_id}");

            // Usar el mismo método que ya tienes para obtener usuario completo
            $usuarioCompleto = $this->getUsuarioCompleto($user->usu_id);

            if (!$usuarioCompleto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontró información del usuario',
                    'data' => null
                ], 404);
            }

            // Ocultar campos sensibles
            if (isset($usuarioCompleto->usu_con)) {
                unset($usuarioCompleto->usu_con);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Información del usuario obtenida correctamente',
                'data' => $usuarioCompleto
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo información del usuario logueado: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor',
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener información básica del usuario logueado (optimizada)
     * GET /api/usuario/me/basica
     */
    public function meBasica(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                    'data' => null
                ], 401);
            }

            // Consulta optimizada solo con los campos necesarios
            $usuarioBasico = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->where('tbl_usu.usu_id', $user->usu_id)
                ->select([
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'tbl_per.per_nom as perfil',
                    'tbl_est.est_nom as estado',
                    'tbl_usu.oficin_codigo',
                    'gaf_oficin.oficin_nombre',
                    'gaf_tofici.tofici_descripcion as tipo_oficina',
                    'gaf_instit.instit_codigo',
                    'gaf_instit.instit_nombre',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_usuario"),
                    DB::raw("CONCAT(COALESCE(gaf_tofici.tofici_descripcion, ''), ' - ', COALESCE(gaf_oficin.oficin_nombre, '')) as oficina_completa")
                ])
                ->first();

            if (!$usuarioBasico) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontró información del usuario',
                    'data' => null
                ], 404);
            }

            $response = [
                'usu_id' => $usuarioBasico->usu_id,
                'nombre_usuario' => trim($usuarioBasico->nombre_usuario),
                'usu_cor' => $usuarioBasico->usu_cor,
                'perfil' => $usuarioBasico->perfil,
                'estado' => $usuarioBasico->estado,
                'institucion' => [
                    'instit_codigo' => $usuarioBasico->instit_codigo,
                    'instit_nombre' => $usuarioBasico->instit_nombre
                ],
                'oficina' => [
                    'oficin_codigo' => $usuarioBasico->oficin_codigo,
                    'oficin_nombre' => $usuarioBasico->oficin_nombre,
                    'tipo_oficina' => $usuarioBasico->tipo_oficina,
                    'oficina_completa' => $usuarioBasico->oficina_completa
                ],
                'tiene_oficina_asignada' => !is_null($usuarioBasico->oficin_codigo)
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Información básica del usuario obtenida correctamente',
                'data' => $response
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo información básica del usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor',
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener solo institución del usuario logueado
     * GET /api/usuario/me/institucion
     */
    public function meInstitucion(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                    'data' => null
                ], 401);
            }

            $institucion = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->where('tbl_usu.usu_id', $user->usu_id)
                ->select(['gaf_instit.instit_codigo', 'gaf_instit.instit_nombre'])
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Institución del usuario obtenida correctamente',
                'data' => [
                    'instit_codigo' => $institucion->instit_codigo ?? null,
                    'instit_nombre' => $institucion->instit_nombre ?? null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo institución del usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor',
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener solo oficina del usuario logueado
     * GET /api/usuario/me/oficina
     */
    public function meOficina(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                    'data' => null
                ], 401);
            }

            $oficina = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
                ->where('tbl_usu.usu_id', $user->usu_id)
                ->select([
                    'gaf_oficin.oficin_codigo',
                    'gaf_oficin.oficin_nombre',
                    'gaf_oficin.oficin_direccion',
                    'gaf_oficin.oficin_telefono',
                    'gaf_oficin.oficin_diremail',
                    'gaf_tofici.tofici_descripcion',
                    DB::raw("CONCAT(COALESCE(gaf_tofici.tofici_descripcion, ''), ' - ', COALESCE(gaf_oficin.oficin_nombre, '')) as oficina_completa")
                ])
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Oficina del usuario obtenida correctamente',
                'data' => [
                    'oficin_codigo' => $oficina->oficin_codigo ?? null,
                    'oficin_nombre' => $oficina->oficin_nombre ?? null,
                    'oficin_direccion' => $oficina->oficin_direccion ?? null,
                    'oficin_telefono' => $oficina->oficin_telefono ?? null,
                    'oficin_diremail' => $oficina->oficin_diremail ?? null,
                    'tipo_oficina' => $oficina->tofici_descripcion ?? null,
                    'oficina_completa' => $oficina->oficina_completa ?? null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo oficina del usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor',
                'data' => null
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $usuario = $this->getUsuarioCompleto($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Ocultar contraseña
            if (isset($usuario->usu_con)) {
                unset($usuario->usu_con);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario obtenido correctamente',
                'data' => $usuario
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }



/**
 * Store a newly created resource in storage.
 * ✅ CORREGIDO: Método completo sin líneas sueltas
 */
public function store(Request $request)
{
    try {
        Log::info("👤 Intentando crear usuario con email: " . $request->get('usu_cor', 'N/A'));
        
        $validator = Validator::make($request->all(), [
            'usu_nom' => 'required|string|max:100',
            'usu_nom2' => 'nullable|string|max:100',
            'usu_ape' => 'required|string|max:100',
            'usu_ape2' => 'nullable|string|max:100',
            'usu_cor' => 'required|email|unique:tbl_usu,usu_cor|max:100',
            'usu_ced' => 'required|string|unique:tbl_usu,usu_ced|max:10',
            'usu_con' => 'required|string|min:6|max:64',
            'usu_tel' => 'nullable|string|max:10',
            'usu_dir' => 'nullable|string|max:100',
            'per_id' => 'required|integer|exists:tbl_per,per_id',
            'est_id' => 'required|integer|exists:tbl_est,est_id',
            'oficin_codigo' => 'nullable|integer|exists:gaf_oficin,oficin_codigo',
            'usu_descripcion' => 'nullable|string',
            'usu_fecha_nacimiento' => 'nullable|date|before:today'
        ]);

        if ($validator->fails()) {
            Log::warning("❌ Validación fallida para usuario: " . json_encode($validator->errors()));
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        DB::beginTransaction();

        $usuarioData = $request->only([
            'usu_nom', 'usu_nom2', 'usu_ape', 'usu_ape2', 'usu_cor',
            'usu_ced', 'usu_tel', 'usu_dir', 'per_id', 'est_id',
            'oficin_codigo', 'usu_descripcion', 'usu_fecha_nacimiento'
        ]);

        $usuarioData['usu_con'] = $request->usu_con;
        $usuarioData['usu_fecha_registro'] = Carbon::now();
        $usuarioData['usu_deshabilitado'] = false;
        $usuarioData['usu_intentos_fallidos'] = 0;
        $usuarioData['usu_cre'] = Carbon::now();

        try {
            if (Auth::check() && Auth::user()) {
                $usuarioData['usu_creado_por'] = Auth::user()->usu_id;
                $usuarioData['usu_editado_por'] = Auth::user()->usu_id;
                Log::info("📝 Usuario creado por: " . Auth::user()->usu_id);
            } else {
                Log::info("📝 Usuario creado sin autenticación");
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ No se pudo obtener usuario autenticado: " . $e->getMessage());
        }

        Log::info("📧 Datos preparados para crear usuario:", [
            'email' => $usuarioData['usu_cor'],
            'oficina' => $usuarioData['oficin_codigo'] ?? 'Sin asignar'
        ]);

        $usuario = Usuario::create($usuarioData);
        
        Log::info("✅ Usuario creado con ID: " . $usuario->usu_id);

        $usuarioCompleto = $this->getUsuarioCompleto($usuario->usu_id);

        if (!$usuarioCompleto) {
            throw new \Exception("No se pudo obtener la información completa del usuario creado");
        }

        DB::commit();

        Log::info("🎉 Usuario creado exitosamente: {$usuario->usu_id} - {$usuarioData['usu_cor']}");

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario creado exitosamente',
            'data' => $usuarioCompleto
        ], 201);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        Log::error("❌ Error de base de datos creando usuario: " . $e->getMessage());
        Log::error("❌ SQL Error Info: " . json_encode($e->errorInfo));
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error de base de datos al crear usuario',
            'data' => [
                'error_code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null,
                'error_detail' => $e->errorInfo[2] ?? null
            ]
        ], 500);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error general creando usuario: " . $e->getMessage());
        Log::error("❌ Archivo: " . $e->getFile() . " Línea: " . $e->getLine());
        Log::error("❌ Trace: " . $e->getTraceAsString());

        return response()->json([
            'status' => 'error',
            'message' => 'Error interno del servidor al crear usuario',
            'data' => [
                'error_type' => get_class($e),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'message' => $e->getMessage()
            ]
        ], 500);
    }
}
/**
 * ✅ MÉTODO DESTROY CORREGIDO - Implementar eliminado lógico
 */
public function destroy($id)
{
    try {
        $usuario = Usuario::find($id);

        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => null
            ], 404);
        }

        DB::beginTransaction();

        // ✅ ELIMINADO LÓGICO: Marcar como deshabilitado en lugar de eliminar
        $usuario->usu_deshabilitado = true;
        $usuario->usu_edi = Carbon::now();

        // Registrar quién lo desactivó
        try {
            if (Auth::check() && Auth::user()) {
                $usuario->usu_editado_por = Auth::user()->usu_id;
            }
        } catch (\Exception $e) {
            // Continuar sin registrar editor si hay error
        }

        $usuario->save();

        // Revocar todos los tokens del usuario deshabilitado
        $usuario->tokens()->delete();

        $usuarioInfo = [
            'usu_id' => $usuario->usu_id,
            'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_ape}"),
            'usu_cor' => $usuario->usu_cor,
            'usu_deshabilitado' => $usuario->usu_deshabilitado
        ];

        DB::commit();

        Log::info("✅ Usuario deshabilitado correctamente: {$usuario->usu_id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario deshabilitado exitosamente',
            'data' => $usuarioInfo
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error deshabilitando usuario: " . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Error al deshabilitar usuario: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}

/**
 * ✅ NUEVO MÉTODO: Reactivar usuario (para el botón de reactivar)
 */
public function reactivate($id)
{
    try {
        $usuario = Usuario::find($id);

        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => null
            ], 404);
        }

        if (!$usuario->usu_deshabilitado) {
            return response()->json([
                'status' => 'warning',
                'message' => 'El usuario ya está activo',
                'data' => null
            ], 400);
        }

        DB::beginTransaction();

        // ✅ REACTIVAR: Marcar como habilitado
        $usuario->usu_deshabilitado = false;
        $usuario->usu_edi = Carbon::now();

        // Registrar quién lo reactivó
        try {
            if (Auth::check() && Auth::user()) {
                $usuario->usu_editado_por = Auth::user()->usu_id;
            }
        } catch (\Exception $e) {
            // Continuar sin registrar editor si hay error
        }

        $usuario->save();

        $usuarioInfo = [
            'usu_id' => $usuario->usu_id,
            'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_ape}"),
            'usu_cor' => $usuario->usu_cor,
            'usu_deshabilitado' => $usuario->usu_deshabilitado
        ];

        DB::commit();

        Log::info("✅ Usuario reactivado correctamente: {$usuario->usu_id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario reactivado exitosamente',
            'data' => $usuarioInfo
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error reactivando usuario: " . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Error al reactivar usuario: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}
  /*
 * Update the specified resource in storage.
 * ✅ CORREGIDO: Sin hasheo de contraseña
 */
public function update(Request $request, $id)
{
    $usuario = Usuario::find($id);

    if (!$usuario) {
        return response()->json([
            'status' => 'error',
            'message' => 'Usuario no encontrado',
            'data' => null
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'usu_nom' => 'sometimes|required|string|max:100',
        'usu_nom2' => 'nullable|string|max:100',
        'usu_ape' => 'sometimes|required|string|max:100',
        'usu_ape2' => 'nullable|string|max:100',
        'usu_cor' => 'sometimes|required|email|unique:tbl_usu,usu_cor,' . $id . ',usu_id|max:100',
        'usu_ced' => 'sometimes|required|string|unique:tbl_usu,usu_ced,' . $id . ',usu_id|max:10',
        'usu_con' => 'nullable|string|min:6|max:64',
        'usu_tel' => 'nullable|string|max:10',
        'usu_dir' => 'nullable|string|max:100',
        'per_id' => 'sometimes|required|integer|exists:tbl_per,per_id',
        'est_id' => 'sometimes|required|integer|exists:tbl_est,est_id',
        'oficin_codigo' => 'nullable|integer|exists:gaf_oficin,oficin_codigo', // ✅ CORREGIDO
        'usu_descripcion' => 'nullable|string',
        'usu_fecha_nacimiento' => 'nullable|date|before:today'
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

        $usuarioData = $request->only([
            'usu_nom', 'usu_nom2', 'usu_ape', 'usu_ape2', 'usu_cor',
            'usu_ced', 'usu_tel', 'usu_dir', 'per_id', 'est_id',
            'oficin_codigo', 'usu_descripcion', 'usu_fecha_nacimiento'
        ]);

        if (!empty($request->usu_con)) {
            $usuarioData['usu_con'] = $request->usu_con;
        }

        $usuarioData['usu_edi'] = Carbon::now();

        try {
            if (Auth::check() && Auth::user()) {
                $usuarioData['usu_editado_por'] = Auth::user()->usu_id;
            }
        } catch (\Exception $e) {
            // Continuar sin registrar editor
        }

        // Log de cambio de oficina
        if (isset($usuarioData['oficin_codigo']) && $usuario->oficin_codigo != $usuarioData['oficin_codigo']) {
            Log::info("🏢 Cambio de oficina detectado:", [
                'usuario_id' => $id,
                'oficina_anterior' => $usuario->oficin_codigo,
                'oficina_nueva' => $usuarioData['oficin_codigo']
            ]);
        }

        $usuario->update($usuarioData);
        $usuarioCompleto = $this->getUsuarioCompleto($id);

        if (isset($usuarioCompleto->usu_con)) {
            unset($usuarioCompleto->usu_con);
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario actualizado exitosamente',
            'data' => $usuarioCompleto
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error actualizando usuario: " . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Error al actualizar usuario: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}
public function asignarOficina(Request $request, $id)
{
    try {
        Log::info("🏢 Intentando asignar oficina al usuario ID: {$id}");
        
        $validator = Validator::make($request->all(), [
            'oficin_codigo' => 'required|integer|exists:gaf_oficin,oficin_codigo',
            'motivo' => 'nullable|string|max:200'
        ]);

        if ($validator->fails()) {
            Log::warning("❌ Validación fallida para asignar oficina usuario {$id}: " . json_encode($validator->errors()));
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        $usuario = Usuario::find($id);

        if (!$usuario) {
            Log::warning("❌ Usuario no encontrado: ID {$id}");
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => null
            ], 404);
        }

        // Verificar que la oficina existe y está activa
        $oficina = DB::table('gaf_oficin')
            ->where('oficin_codigo', $request->oficin_codigo)
            ->where('oficin_ctractual', 1)
            ->first();

        if (!$oficina) {
            Log::warning("❌ Oficina no encontrada o inactiva: {$request->oficin_codigo}");
            return response()->json([
                'status' => 'error',
                'message' => 'La oficina seleccionada no está activa o no existe',
                'data' => null
            ], 400);
        }

        DB::beginTransaction();

        $oficinaAnterior = $usuario->oficin_codigo;
        
        Log::info("🏢 Asignando oficina {$request->oficin_codigo} al usuario {$id}");
        
        $usuario->cambiarOficina($request->oficin_codigo, $request->motivo);
        $usuarioActualizado = $this->getUsuarioCompleto($id);

        if (!$usuarioActualizado) {
            throw new \Exception("No se pudo obtener la información actualizada del usuario");
        }

        DB::commit();

        Log::info("✅ Oficina asignada correctamente al usuario {$id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Oficina asignada exitosamente',
            'data' => [
                'usuario' => $usuarioActualizado,
                'cambio' => [
                    'oficina_anterior' => $oficinaAnterior,
                    'oficina_nueva' => $request->oficin_codigo,
                    'motivo' => $request->motivo
                ]
            ]
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        Log::error("❌ Error de base de datos asignando oficina usuario {$id}: " . $e->getMessage());
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error de base de datos al asignar oficina',
            'data' => [
                'error_code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null
            ]
        ], 500);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error general asignando oficina usuario {$id}: " . $e->getMessage());
        Log::error("❌ Trace: " . $e->getTraceAsString());

        return response()->json([
            'status' => 'error',
            'message' => 'Error interno del servidor al asignar oficina',
            'data' => [
                'error_type' => get_class($e),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'message' => $e->getMessage()
            ]
        ], 500);
    }
}


public function removerOficina(Request $request, $id)
{
    try {
        $usuario = Usuario::find($id);

        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => null
            ], 404);
        }

        if (!$usuario->tieneOficinaAsignada()) {
            return response()->json([
                'status' => 'warning',
                'message' => 'El usuario no tiene oficina asignada',
                'data' => null
            ], 400);
        }

        DB::beginTransaction();

        $oficinaAnterior = $usuario->oficin_codigo;
        
        // ✅ CAMBIO DIRECTO SIN LLAMAR getUsuarioCompleto
        $usuario->update([
            'oficin_codigo' => null,
            'usu_edi' => now()
        ]);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Oficina removida exitosamente',
            'data' => [
                'usuario_id' => $usuario->usu_id,
                'cambio' => [
                    'oficina_anterior' => $oficinaAnterior,
                    'oficina_nueva' => null,
                    'motivo' => $request->motivo
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al remover oficina: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}
   
    /**
     * Toggle user status (enable/disable)
     */
    public function toggleStatus($id)
    {
        try {
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            // Alternar estado de deshabilitado
            $nuevoEstado = !$usuario->usu_deshabilitado;
            $usuario->usu_deshabilitado = $nuevoEstado;
            $usuario->save();

            // Si se desactiva el usuario, revocar todos sus tokens
            if ($nuevoEstado) {
                $usuario->tokens()->delete();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $nuevoEstado ? 'Usuario deshabilitado exitosamente' : 'Usuario habilitado exitosamente',
                'data' => [
                    'usu_id' => $usuario->usu_id,
                    'usu_deshabilitado' => $nuevoEstado,
                    'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_ape}")
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estado: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    

    

    public function getPermissionsDetail($id)
    {
        try {
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            Log::info("🔍 Obteniendo permisos detallados para usuario {$id}");

            // Obtener permisos del perfil del usuario desde tbl_perm_perfil
            $permisosPerfil = DB::table('tbl_perm_perfil')
                ->join('tbl_men', 'tbl_perm_perfil.men_id', '=', 'tbl_men.men_id')
                ->leftJoin('tbl_sub', 'tbl_perm_perfil.sub_id', '=', 'tbl_sub.sub_id')
                ->leftJoin('tbl_opc', 'tbl_perm_perfil.opc_id', '=', 'tbl_opc.opc_id')
                ->leftJoin('tbl_ico as ico_men', 'tbl_men.ico_id', '=', 'ico_men.ico_id')
                ->leftJoin('tbl_ico as ico_sub', 'tbl_sub.ico_id', '=', 'ico_sub.ico_id')
                ->leftJoin('tbl_ico as ico_opc', 'tbl_opc.ico_id', '=', 'ico_opc.ico_id')
                ->where('tbl_perm_perfil.per_id', $usuario->per_id)
                ->where('tbl_perm_perfil.perm_per_activo', true)
                ->where('tbl_men.men_activo', true)
                ->select(
                    'tbl_perm_perfil.men_id',
                    'tbl_perm_perfil.sub_id',
                    'tbl_perm_perfil.opc_id',
                    'tbl_men.men_nom',
                    'tbl_men.men_componente',
                    'ico_men.ico_nom as men_icon_nombre',
                    'tbl_sub.sub_nom',
                    'tbl_sub.sub_componente',
                    'tbl_sub.sub_activo',
                    'ico_sub.ico_nom as sub_icon_nombre',
                    'tbl_opc.opc_nom',
                    'tbl_opc.opc_componente',
                    'tbl_opc.opc_activo',
                    'ico_opc.ico_nom as opc_icon_nombre'
                )
                ->get();

            // ✅ SIMPLIFICADO: Obtener permisos individuales usando solo tbl_usu_perm
            $permisosUsuario = DB::table('tbl_usu_perm')
                ->where('usu_id', $id)
                ->get();

            Log::info("📊 Permisos encontrados: perfil={$permisosPerfil->count()}, usuario={$permisosUsuario->count()}");

            // Crear un Set de permisos que el usuario tiene activos
            $permisosActivosUsuario = $permisosUsuario->mapWithKeys(function ($item) {
                $key = $item->men_id . '-' . ($item->sub_id ?? 'null') . '-' . ($item->opc_id ?? 'null');
                return [$key => true];
            });

            // Organizar permisos del perfil en estructura de árbol
            $menuTree = [];

            foreach ($permisosPerfil as $item) {
                $permisoKey = $item->men_id . '-' . ($item->sub_id ?? 'null') . '-' . ($item->opc_id ?? 'null');
                $usuarioTienePermiso = isset($permisosActivosUsuario[$permisoKey]);

                // Crear menú si no existe
                if (!isset($menuTree[$item->men_id])) {
                    $menuTree[$item->men_id] = [
                        'men_id' => $item->men_id,
                        'men_nom' => $item->men_nom,
                        'men_componente' => $item->men_componente,
                        'ico_nombre' => $item->men_icon_nombre,
                        'has_permission' => $item->sub_id === null && $item->opc_id === null ? $usuarioTienePermiso : false,
                        'submenus' => []
                    ];
                }

                // Agregar submenú si existe y está activo
                if ($item->sub_id && $item->sub_activo) {
                    $submenuKey = $item->sub_id;

                    if (!isset($menuTree[$item->men_id]['submenus'][$submenuKey])) {
                        $menuTree[$item->men_id]['submenus'][$submenuKey] = [
                            'sub_id' => $item->sub_id,
                            'sub_nom' => $item->sub_nom,
                            'sub_componente' => $item->sub_componente,
                            'ico_nombre' => $item->sub_icon_nombre,
                            'has_permission' => $item->opc_id === null ? $usuarioTienePermiso : false,
                            'opciones' => []
                        ];
                    }

                    // Agregar opción si existe y está activa
                    if ($item->opc_id && $item->opc_activo) {
                        $menuTree[$item->men_id]['submenus'][$submenuKey]['opciones'][] = [
                            'opc_id' => $item->opc_id,
                            'opc_nom' => $item->opc_nom,
                            'opc_componente' => $item->opc_componente,
                            'ico_nombre' => $item->opc_icon_nombre,
                            'has_permission' => $usuarioTienePermiso
                        ];
                    }
                }
            }

            // Convertir submenus de asociativo a indexado
            foreach ($menuTree as &$menu) {
                $menu['submenus'] = array_values($menu['submenus']);
            }

            Log::info("✅ Estructura de permisos construida exitosamente");

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos del usuario obtenidos correctamente',
                'data' => [
                    'usuario' => [
                        'usu_id' => $usuario->usu_id,
                        'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_nom2} {$usuario->usu_ape} {$usuario->usu_ape2}"),
                        'usu_cor' => $usuario->usu_cor,
                        'per_id' => $usuario->per_id
                    ],
                    'permisos' => array_values($menuTree),
                    'permisos_usuario_activos' => $permisosUsuario->count(),
                    'total_permisos_disponibles' => $permisosPerfil->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en getPermissionsDetail: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos del usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
    /**
 * Obtener perfiles que el usuario autenticado puede visualizar
 */
public function getPerfilesPermitidos(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
                'data' => []
            ], 401);
        }

        $perfilesPermitidos = $this->getPerfilesVisiblesPorUsuario($user->usu_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Perfiles permitidos obtenidos correctamente',
            'data' => $perfilesPermitidos
        ]);
    } catch (\Exception $e) {
        Log::error("❌ Error obteniendo perfiles permitidos: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener perfiles permitidos',
            'data' => []
        ], 500);
    }
}

/**
 * Método privado para obtener perfiles visibles por usuario
 */
private function getPerfilesVisiblesPorUsuario($usuarioId)
{
    return DB::table('tbl_per')
        ->join('tbl_perm_perfil_visibilidad', 'tbl_per.per_id', '=', 'tbl_perm_perfil_visibilidad.per_id_visible')
        ->where('tbl_perm_perfil_visibilidad.usu_id', $usuarioId)
        ->where('tbl_perm_perfil_visibilidad.perm_per_vis_activo', true)
        ->where('tbl_per.per_activo', true)
        ->select('tbl_per.per_id', 'tbl_per.per_nom')
        ->orderBy('tbl_per.per_nom')
        ->get();
}

/**
 * Asignar permisos de visibilidad de perfiles a un usuario
 */
public function asignarPerfilVisibilidad(Request $request, $usuarioId)
{
    $validator = Validator::make($request->all(), [
        'perfiles_ids' => 'required|array',
        'perfiles_ids.*' => 'integer|exists:tbl_per,per_id'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Datos de validación incorrectos',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $usuario = Usuario::find($usuarioId);
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        DB::beginTransaction();

        $currentUser = Auth::user();
        
        // Desactivar permisos existentes
        DB::table('tbl_perm_perfil_visibilidad')
            ->where('usu_id', $usuarioId)
            ->update(['perm_per_vis_activo' => false]);

        // Crear/reactivar nuevos permisos
        $asignados = 0;
        foreach ($request->perfiles_ids as $perfilId) {
            $existe = DB::table('tbl_perm_perfil_visibilidad')
                ->where('usu_id', $usuarioId)
                ->where('per_id_visible', $perfilId)
                ->first();

            if ($existe) {
                // Reactivar existente
                DB::table('tbl_perm_perfil_visibilidad')
                    ->where('usu_id', $usuarioId)
                    ->where('per_id_visible', $perfilId)
                    ->update([
                        'perm_per_vis_activo' => true,
                        'perm_per_vis_edi' => now()
                    ]);
            } else {
                // Crear nuevo
                DB::table('tbl_perm_perfil_visibilidad')->insert([
                    'usu_id' => $usuarioId,
                    'per_id_visible' => $perfilId,
                    'perm_per_vis_activo' => true,
                    'perm_per_vis_creado_por' => $currentUser ? $currentUser->usu_id : null,
                    'perm_per_vis_cre' => now(),
                    'perm_per_vis_edi' => now()
                ]);
            }
            $asignados++;
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => "Se asignaron {$asignados} permisos de visibilidad de perfiles",
            'data' => ['permisos_asignados' => $asignados]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("❌ Error asignando visibilidad de perfiles: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al asignar permisos de visibilidad'
        ], 500);
    }
}

 

    /**
     * ✅ RESTAURAR: Endpoin
     *  para obtener permisos (para AsgiPerUsWindows)
     */
    public function getPermissions($id)
    {
        return $this->getPermissionsDetail($id);
    }

    /**
     * Asignar permisos específicos a un usuario
     */
    public function assignPermissions(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*.men_id' => 'required|integer|exists:tbl_men,men_id',
            'permissions.*.sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'permissions.*.opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'permissions.*.grant' => 'required|boolean'
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
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            Log::info("🔧 Iniciando asignación de permisos para usuario {$id}");

            DB::beginTransaction();

            $processedCount = 0;
            $errores = [];

            foreach ($request->permissions as $permission) {
                try {
                    $menId = $permission['men_id'];
                    $subId = $permission['sub_id'];
                    $opcId = $permission['opc_id'];
                    $grant = $permission['grant'];

                    Log::info("🔍 Procesando permiso: menú={$menId}, sub={$subId}, opc={$opcId}, grant={$grant}");

                    // ✅ SIMPLIFICADO: Verificar que el permiso esté disponible en el perfil del usuario
                    $perfilHasPermission = DB::table('tbl_perm_perfil')
                        ->where('per_id', $usuario->per_id)
                        ->where('men_id', $menId)
                        ->where(function ($query) use ($subId) {
                            if ($subId !== null) {
                                $query->where('sub_id', $subId);
                            } else {
                                $query->whereNull('sub_id');
                            }
                        })
                        ->where(function ($query) use ($opcId) {
                            if ($opcId !== null) {
                                $query->where('opc_id', $opcId);
                            } else {
                                $query->whereNull('opc_id');
                            }
                        })
                        ->where('perm_per_activo', true)
                        ->exists();

                    if (!$perfilHasPermission) {
                        $errores[] = "Permiso no disponible en perfil para menú {$menId}";
                        Log::warning("⚠️ Permiso no disponible en perfil: menú={$menId}, sub={$subId}, opc={$opcId}");
                        continue;
                    }

                    // ✅ USAR SOLO TBL_USU_PERM - es más simple y funcional
                    $userPermissionData = [
                        'usu_id' => $id,
                        'men_id' => $menId,
                        'sub_id' => $subId,
                        'opc_id' => $opcId
                    ];

                    // Verificar si ya existe este permiso específico para el usuario
                    $existingUserPermission = DB::table('tbl_usu_perm')
                        ->where($userPermissionData)
                        ->exists();

                    if ($grant && !$existingUserPermission) {
                        // ✅ OTORGAR PERMISO: Insertar en tbl_usu_perm
                        $userPermissionData['created_at'] = now();

                        DB::table('tbl_usu_perm')->insert($userPermissionData);
                        $processedCount++;
                        Log::info("✅ Permiso otorgado: menú={$menId}, sub={$subId}, opc={$opcId}");
                    } elseif (!$grant && $existingUserPermission) {
                        // ✅ REVOCAR PERMISO: Eliminar de tbl_usu_perm
                        DB::table('tbl_usu_perm')->where($userPermissionData)->delete();
                        $processedCount++;
                        Log::info("✅ Permiso revocado: menú={$menId}, sub={$subId}, opc={$opcId}");
                    }
                } catch (\Exception $e) {
                    $errores[] = "Error procesando permiso menú {$menId}: " . $e->getMessage();
                    Log::error("❌ Error procesando permiso: " . $e->getMessage());
                }
            }

            DB::commit();

            $mensaje = "Se procesaron {$processedCount} cambios de permisos correctamente";
            if (!empty($errores) && $processedCount === 0) {
                $mensaje = "No se procesaron cambios. Errores: " . implode(', ', array_slice($errores, 0, 2));
            } elseif (!empty($errores)) {
                $mensaje .= ". Algunos errores: " . implode(', ', array_slice($errores, 0, 1));
            }

            Log::info("✅ Asignación de permisos completada: {$processedCount} cambios procesados");

            return response()->json([
                'status' => $processedCount > 0 ? 'success' : 'warning',
                'message' => $mensaje,
                'data' => [
                    'changes_processed' => $processedCount,
                    'errors' => $errores,
                    'usuario_id' => $id,
                    'perfil_id' => $usuario->per_id,
                    'total_permissions_attempted' => count($request->permissions)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error general en assignPermissions: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al asignar permisos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener permisos activos de un usuario (combinando perfil + individuales)
     */
   
/**
 * OPCIONAL: Método para limpiar registros problemáticos de tbl_perm_usuario
 */
public function cleanupBrokenPermissions()
{
    try {
        // Contar registros problemáticos
        $brokenCount = DB::table('tbl_perm_usuario')
            ->where('perm_tipo', 'NOT IN', DB::raw("('C'::bpchar, 'D'::bpchar)"))
            ->count();

        Log::info("🧹 Encontrados {$brokenCount} registros problemáticos en tbl_perm_usuario");

        if ($brokenCount > 0) {
            // Opcional: Eliminar registros problemáticos
            // DB::table('tbl_perm_usuario')
            //     ->where('perm_tipo', 'NOT IN', DB::raw("('C'::bpchar, 'D'::bpchar)"))
            //     ->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => "Encontrados {$brokenCount} registros problemáticos",
            'broken_records' => $brokenCount
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
}


    /**
     * ✅ RESTAURAR: Endpoin
     *  para obtener permisos (para AsgiPerUsWindows)
     */
   
    /**
     * Asignar permisos específicos a un usuario
     */


    /**
     * Obtener permisos activos de un usuario (combinando perfil + individuales)
     */
    public function getActivePermissions($id)
    {
        try {
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // ✅ CORREGIDO: Obtener permisos disponibles del perfil desde tbl_perm_perfil
            $permisosDisponibles = DB::table('tbl_perm_perfil')
                ->join('tbl_men', 'tbl_perm_perfil.men_id', '=', 'tbl_men.men_id')
                ->leftJoin('tbl_sub', 'tbl_perm_perfil.sub_id', '=', 'tbl_sub.sub_id')
                ->leftJoin('tbl_opc', 'tbl_perm_perfil.opc_id', '=', 'tbl_opc.opc_id')
                ->where('tbl_perm_perfil.per_id', $usuario->per_id)
                ->where('tbl_perm_perfil.perm_per_activo', true) // ✅ AGREGADO
                ->where('tbl_men.men_activo', true) // ✅ CORREGIDO
                ->select(
                    'tbl_perm_perfil.men_id',
                    'tbl_perm_perfil.sub_id',
                    'tbl_perm_perfil.opc_id',
                    'tbl_men.men_nom',
                    'tbl_sub.sub_nom',
                    'tbl_opc.opc_nom'
                )
                ->get();

            // ✅ CORREGIDO: Obtener permisos específicamente asignados al usuario desde tbl_perm_usuario
            $permisosUsuario = DB::table('tbl_perm_usuario')
                ->where('usu_id', $id)
                ->where('perm_usu_activo', true)
                ->where('perm_tipo', 'C') // Solo permisos concedidos
                ->get();

            // Crear conjunto de permisos activos del usuario
            $permisosActivos = [];

            foreach ($permisosDisponibles as $permisoDisponible) {
                // Verificar si el usuario tiene este permiso específico activado
                $tienePermiso = $permisosUsuario->contains(function ($permisoUser) use ($permisoDisponible) {
                    return $permisoUser->men_id == $permisoDisponible->men_id &&
                        ($permisoUser->sub_id == $permisoDisponible->sub_id ||
                            ($permisoUser->sub_id === null && $permisoDisponible->sub_id === null)) &&
                        ($permisoUser->opc_id == $permisoDisponible->opc_id ||
                            ($permisoUser->opc_id === null && $permisoDisponible->opc_id === null));
                });

                if ($tienePermiso) {
                    $permisosActivos[] = [
                        'men_id' => $permisoDisponible->men_id,
                        'sub_id' => $permisoDisponible->sub_id,
                        'opc_id' => $permisoDisponible->opc_id,
                        'men_nom' => $permisoDisponible->men_nom,
                        'sub_nom' => $permisoDisponible->sub_nom,
                        'opc_nom' => $permisoDisponible->opc_nom
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos activos obtenidos correctamente',
                'data' => [
                    'usuario_id' => $id,
                    'permisos_activos' => $permisosActivos,
                    'total_disponibles' => $permisosDisponibles->count(),
                    'total_activos' => count($permisosActivos)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error en getActivePermissions: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos activos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Copiar permisos de un usuario a otro (del mismo perfil)
     */
    public function copyUserPermissions(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'target_user_id' => 'required|integer|exists:tbl_usu,usu_id|different:' . $id,
            'overwrite' => 'boolean'
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
            $usuarioOrigen = Usuario::find($id);
            $usuarioDestino = Usuario::find($request->target_user_id);

            if (!$usuarioOrigen || !$usuarioDestino) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Verificar que ambos usuarios tengan el mismo perfil
            if ($usuarioOrigen->per_id !== $usuarioDestino->per_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden copiar permisos entre usuarios del mismo perfil',
                    'data' => null
                ], 400);
            }

            DB::beginTransaction();

            $overwrite = $request->overwrite ?? false;

            // Si se especifica sobrescribir, desactivar permisos existentes del usuario destino
            if ($overwrite) {
                DB::table('tbl_perm_usuario')
                    ->where('usu_id', $request->target_user_id)
                    ->update([
                        'perm_usu_activo' => false,
                        'perm_usu_edi' => now()
                    ]);
            }

            // ✅ CORREGIDO: Obtener permisos del usuario origen desde tbl_perm_usuario
            $permisosOrigen = DB::table('tbl_perm_usuario')
                ->where('usu_id', $id)
                ->where('perm_usu_activo', true)
                ->where('perm_tipo', 'C')
                ->get();

            $copiedCount = 0;

            // Copiar cada permiso
            foreach ($permisosOrigen as $permiso) {
                $newPermission = [
                    'usu_id' => $request->target_user_id,
                    'men_id' => $permiso->men_id,
                    'sub_id' => $permiso->sub_id,
                    'opc_id' => $permiso->opc_id
                ];

                // Verificar si ya existe (solo si no se sobrescribe)
                if (!$overwrite) {
                    $exists = DB::table('tbl_perm_usuario')
                        ->where($newPermission)
                        ->where('perm_usu_activo', true)
                        ->exists();

                    if ($exists) {
                        continue;
                    }
                }

                $newPermission['perm_tipo'] = 'C';
                $newPermission['perm_usu_activo'] = true;
                $newPermission['perm_usu_cre'] = now();
                $newPermission['perm_usu_edi'] = now();

                DB::table('tbl_perm_usuario')->insert($newPermission);
                $copiedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se copiaron {$copiedCount} permisos correctamente",
                'data' => ['permissions_copied' => $copiedCount]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en copyUserPermissions: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar permisos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get complete user information
     * ✅ CORRECCIÓN: Mejorar consulta para incluir toda la información necesaria
     */
    /**
     * Get complete user information
     * ✅ CORRECCIÓN: Mejorar consulta para incluir toda la información necesaria
     */
 private function getUsuarioCompleto($id)
{
    return DB::table('tbl_usu')
        ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
        ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
        ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo') // ✅ CORREGIDO
        ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo') // ✅ CORREGIDO
        ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo') // ✅ CORREGIDO
        ->leftJoin('tbl_usu as creador', 'tbl_usu.usu_creado_por', '=', 'creador.usu_id')
        ->leftJoin('tbl_usu as editor', 'tbl_usu.usu_editado_por', '=', 'editor.usu_id')
        ->select(
            'tbl_usu.*',
            'tbl_per.per_nom as perfil',
            'tbl_est.est_nom as estado',
            // ✅ CAMPOS DE OFICINA CORREGIDOS
            'gaf_oficin.oficin_nombre as oficina_nombre',
            'gaf_oficin.oficin_direccion as oficina_direccion',
            'gaf_tofici.tofici_descripcion as tipo_oficina',
            'gaf_instit.instit_nombre as institucion',
            'creador.usu_nom as creado_por_nombre',
            'editor.usu_nom as editado_por_nombre',
            DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
        )
        ->where('tbl_usu.usu_id', $id)
        ->first();
}
    /**
     * Get form options for user creation/editing
     */
    public function getFormOptions()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->where('per_activo', true)
                ->select('per_id as value', 'per_nom as label')
                ->orderBy('per_nom')
                ->get();

            $estados = DB::table('tbl_est')
                ->where('est_activo', true)
                ->select('est_id as value', 'est_nom as label')
                ->orderBy('est_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Opciones obtenidas correctamente',
                'data' => [
                    'perfiles' => $perfiles,
                    'estados' => $estados
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener opciones: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de usuarios
     */
    public function getStats()
    {
        try {
            $stats = [
                'total_usuarios' => DB::table('tbl_usu')->count(),
                'usuarios_activos' => DB::table('tbl_usu')->where('usu_deshabilitado', false)->count(),
                'usuarios_inactivos' => DB::table('tbl_usu')->where('usu_deshabilitado', true)->count(),
                'usuarios_por_perfil' => DB::table('tbl_usu')
                    ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                    ->select('tbl_per.per_nom', DB::raw('COUNT(*) as cantidad'))
                    ->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                    ->get(),
                'ultimos_registros' => DB::table('tbl_usu')
                    ->select('usu_fecha_registro')
                    ->where('usu_fecha_registro', '>=', Carbon::now()->subDays(30))
                    ->count()
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Estadísticas obtenidas correctamente',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Búsqueda avanzada de usuarios
     */
    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:2',
                'fields' => 'array|in:nombre,email,cedula,perfil',
                'limit' => 'integer|min:1|max:50'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros de búsqueda inválidos',
                    'errors' => $validator->errors(),
                    'data' => []
                ], 422);
            }

            $query = $request->input('query');
            $fields = $request->input('fields', ['nombre', 'email', 'cedula']);
            $limit = $request->input('limit', 20);

            $searchQuery = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_per.per_nom as perfil',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
                );

            $searchQuery->where(function ($q) use ($query, $fields) {
                if (in_array('nombre', $fields)) {
                    $q->orWhere('tbl_usu.usu_nom', 'ILIKE', "%{$query}%")
                        ->orWhere('tbl_usu.usu_ape', 'ILIKE', "%{$query}%");
                }
                if (in_array('email', $fields)) {
                    $q->orWhere('tbl_usu.usu_cor', 'ILIKE', "%{$query}%");
                }
                if (in_array('cedula', $fields)) {
                    $q->orWhere('tbl_usu.usu_ced', 'ILIKE', "%{$query}%");
                }
                if (in_array('perfil', $fields)) {
                    $q->orWhere('tbl_per.per_nom', 'ILIKE', "%{$query}%");
                }
            });

            $results = $searchQuery->limit($limit)->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Búsqueda completada',
                'data' => [
                    'query' => $query,
                    'results' => $results,
                    'total_found' => $results->count(),
                    'fields_searched' => $fields
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error en la búsqueda: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Obtener permisos del usuario para AuthController
     */
    public function getUserPermissions($id)
    {
        try {
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Usar el método del AuthController para obtener permisos
            $authController = new AuthController();
            $permisos = $authController->getUserMenus($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos del usuario obtenidos correctamente',
                'data' => [
                    'usuario_id' => $id,
                    'perfil_id' => $usuario->per_id,
                    'permisos' => $permisos
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Cambiar contraseña del usuario
     */
    public function changePassword(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|max:64',
                'confirm_password' => 'required|string|same:new_password'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            if (!Hash::check($request->current_password, $usuario->usu_con)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La contraseña actual es incorrecta',
                    'data' => null
                ], 400);
            }

            DB::beginTransaction();

            $usuario->update([
                'usu_con' => Hash::make($request->new_password),
                'usu_fecha_cambio_clave' => Carbon::now()
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña cambiada exitosamente',
                'data' => null
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar contraseña: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Reset user password (admin action)
     */
    public function resetPassword(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'new_password' => 'required|string|min:6|max:64'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            $usuario->update([
                'usu_con' => Hash::make($request->new_password),
                'usu_fecha_actualizacion_clave' => Carbon::now(),
                'usu_intentos_fallidos' => 0,
                'usu_bloqueado_hasta' => null
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña restablecida exitosamente',
                'data' => null
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al restablecer contraseña: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
    
}
