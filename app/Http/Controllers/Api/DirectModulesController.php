<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DirectModulesController extends Controller
{
    /**
     * Obtener todos los perfiles con el estado de sus módulos directos
     */
    public function getPerfilesWithDirectModules()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->where('per_activo', true)
                ->select('per_id', 'per_nom', 'per_descripcion', 'per_nivel')
                ->orderBy('per_nom')
                ->get();

            foreach ($perfiles as $perfil) {
                $modulosDirectos = $this->getModulosDirectosConPermisos($perfil->per_id);
                $perfil->modulos_directos = $modulosDirectos;

                // Estadísticas actualizadas
                $perfil->estadisticas = [
                    'total_modulos_directos' => count($modulosDirectos),
                    'modulos_con_acceso' => count($modulosDirectos),
                    'modulos_con_botones' => count(array_filter($modulosDirectos, fn($m) => $m['tiene_botones_configurados']))
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Perfiles con módulos directos obtenidos correctamente',
                'perfiles' => $perfiles
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo perfiles con módulos directos: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener estado de módulos directos para un perfil específico
     */
    public function getModulosDirectosForPerfil($perfilId)
    {
        try {
            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            $modulosDirectos = $this->getModulosDirectosConPermisos($perfilId);

            return response()->json([
                'status' => 'success',
                'perfil' => $perfil,
                'modulos_directos' => $modulosDirectos
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo módulos directos para perfil {$perfilId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO CORREGIDO: Asignar/Revocar acceso completo a un módulo directo
     */
    public function toggleModuloDirectoAccess(Request $request, $perfilId)
    {
        $validator = Validator::make($request->all(), [
            'men_id' => 'nullable|integer|exists:tbl_men,men_id',
            'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'grant_access' => 'required|boolean',
            'tipo' => 'required|in:menu,submenu,opcion'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $menId = $request->men_id;
            $subId = $request->sub_id;
            $opcId = $request->opc_id;
            $grantAccess = $request->grant_access;
            $tipo = $request->tipo;

            Log::info("🔄 Processing module access change", [
                'perfil_id' => $perfilId,
                'modulo' => ['men_id' => $menId, 'sub_id' => $subId, 'opc_id' => $opcId],
                'tipo' => $tipo,
                'grant_access' => $grantAccess
            ]);

            // ✅ VERIFICAR QUE ES VENTANA DIRECTA
            if (!$this->esVentanaDirecta($menId, $subId, $opcId, $tipo)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El módulo especificado no es una ventana directa'
                ], 422);
            }

            if ($grantAccess) {
                $result = $this->otorgarAccesoCompletoCorregido($perfilId, $menId, $subId, $opcId, $tipo);
            } else {
                $result = $this->revocarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo);
            }

            DB::commit();

            Log::info("✅ Module access changed successfully", [
                'result' => $result
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result['data']
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error en toggle módulo directo", [
                'perfil_id' => $perfilId,
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al modificar acceso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NUEVO: Verificar si un módulo es ventana directa
     */
    private function esVentanaDirecta($menId, $subId, $opcId, $tipo)
    {
        try {
            switch ($tipo) {
                case 'menu':
                    return DB::table('tbl_men')
                        ->where('men_id', $menId)
                        ->where('men_ventana_directa', true)
                        ->where('men_activo', true)
                        ->exists();

                case 'submenu':
                    return DB::table('tbl_sub')
                        ->where('sub_id', $subId)
                        ->where('sub_ventana_directa', true)
                        ->where('sub_activo', true)
                        ->exists();

                case 'opcion':
                    return DB::table('tbl_opc')
                        ->where('opc_id', $opcId)
                        ->where('opc_ventana_directa', true)
                        ->where('opc_activo', true)
                        ->exists();

                default:
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("❌ Error verificando ventana directa", [
                'tipo' => $tipo,
                'ids' => ['men_id' => $menId, 'sub_id' => $subId, 'opc_id' => $opcId],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * ✅ MÉTODO COMPLETAMENTE REESCRITO: Otorgar acceso completo
     */
    private function otorgarAccesoCompletoCorregido($perfilId, $menId, $subId, $opcId, $tipo)
    {
        try {
            Log::info("🚀 Iniciando otorgamiento de acceso completo", [
                'perfil_id' => $perfilId,
                'modulo' => ['men_id' => $menId, 'sub_id' => $subId, 'opc_id' => $opcId],
                'tipo' => $tipo
            ]);

            // PASO 1: Crear permiso de módulo
            DB::table('tbl_perm_perfil')->updateOrInsert(
                [
                    'per_id' => $perfilId,
                    'men_id' => $menId,
                    'sub_id' => $subId,
                    'opc_id' => $opcId
                ],
                [
                    'perm_per_activo' => true,
                    'perm_per_cre' => now(),
                    'perm_per_edi' => now()
                ]
            );

            Log::info("✅ Permiso de módulo creado/actualizado");

            // PASO 2: Asegurar que existen relaciones de botones y crear permisos
            $botonesAsignados = $this->asegurarBotonesYPermisosCompletos($perfilId, $menId, $subId, $opcId, $tipo);

            Log::info("✅ Acceso completo otorgado", [
                'botones_asignados' => $botonesAsignados
            ]);

            return [
                'message' => "Acceso completo otorgado correctamente ({$botonesAsignados} botones configurados)",
                'data' => [
                    'permisos_creados' => 1,
                    'botones_asignados' => $botonesAsignados,
                    'tipo' => $tipo
                ]
            ];

        } catch (\Exception $e) {
            Log::error("❌ Error en otorgarAccesoCompletoCorregido", [
                'perfil_id' => $perfilId,
                'modulo' => ['men_id' => $menId, 'sub_id' => $subId, 'opc_id' => $opcId],
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * ✅ MÉTODO NUEVO Y COMPLETO: Asegurar botones y crear permisos
     */
    private function asegurarBotonesYPermisosCompletos($perfilId, $menId, $subId, $opcId, $tipo)
    {
        try {
            Log::info("🔧 Asegurando botones y permisos para {$tipo}", [
                'modulo' => ['men_id' => $menId, 'sub_id' => $subId, 'opc_id' => $opcId]
            ]);

            // CONFIGURACIÓN POR TIPO DE MÓDULO
            $configuracion = $this->getConfiguracionPorTipo($tipo, $menId, $subId, $opcId);
            
            // PASO 1: Verificar y crear relaciones básicas de botones si no existen
            $this->verificarYCrearRelacionesBotones($configuracion);

            // PASO 2: Obtener todos los botones existentes para este módulo
            $botonesExistentes = $this->obtenerBotonesDelModulo($configuracion);

            Log::info("🔘 Botones encontrados para el módulo", [
                'cantidad' => $botonesExistentes->count(),
                'botones' => $botonesExistentes->pluck('bot_codigo')->toArray()
            ]);

            // PASO 3: Crear permisos de botones para el perfil
            $botonesConPermiso = 0;
            foreach ($botonesExistentes as $boton) {
                $permisoData = [
                    'per_id' => $perfilId,
                    'men_id' => $menId,
                    'sub_id' => $subId,
                    'opc_id' => $opcId,
                    'bot_id' => $boton->bot_id,
                    'perm_bot_per_activo' => true,
                    'perm_bot_per_cre' => now(),
                    'perm_bot_per_edi' => now()
                ];

                DB::table('tbl_perm_bot_perfil')->updateOrInsert(
                    [
                        'per_id' => $perfilId,
                        'men_id' => $menId,
                        'sub_id' => $subId,
                        'opc_id' => $opcId,
                        'bot_id' => $boton->bot_id
                    ],
                    $permisoData
                );

                $botonesConPermiso++;
                Log::info("✅ Permiso de botón creado: {$boton->bot_codigo}");
            }

            Log::info("✅ Permisos de botones procesados exitosamente", [
                'total_botones' => $botonesExistentes->count(),
                'permisos_creados' => $botonesConPermiso
            ]);

            return $botonesConPermiso;

        } catch (\Exception $e) {
            Log::error("❌ Error en asegurarBotonesYPermisosCompletos", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * ✅ MÉTODO AUXILIAR: Obtener configuración por tipo de módulo
     */
    private function getConfiguracionPorTipo($tipo, $menId, $subId, $opcId)
    {
        switch ($tipo) {
            case 'menu':
                return [
                    'tipo' => 'menu',
                    'tabla_relaciones' => 'tbl_men_bot',
                    'campo_modulo' => 'men_id',
                    'valor_modulo' => $menId,
                    'campo_activo' => 'men_bot_activo',
                    'campos_insercion' => [
                        'men_id' => $menId,
                        'men_bot_orden' => 0,
                        'men_bot_requerido' => false,
                        'men_bot_activo' => true,
                        'men_bot_cre' => now(),
                        'men_bot_edi' => now()
                    ]
                ];

            case 'submenu':
                return [
                    'tipo' => 'submenu',
                    'tabla_relaciones' => 'tbl_sub_bot',
                    'campo_modulo' => 'sub_id',
                    'valor_modulo' => $subId,
                    'campo_activo' => 'sub_bot_activo',
                    'campos_insercion' => [
                        'sub_id' => $subId,
                        'sub_bot_orden' => 0,
                        'sub_bot_requerido' => false,
                        'sub_bot_activo' => true,
                        'sub_bot_cre' => now(),
                        'sub_bot_edi' => now()
                    ]
                ];

            case 'opcion':
                return [
                    'tipo' => 'opcion',
                    'tabla_relaciones' => 'tbl_opc_bot',
                    'campo_modulo' => 'opc_id',
                    'valor_modulo' => $opcId,
                    'campo_activo' => 'opc_bot_activo',
                    'campos_insercion' => [
                        'opc_id' => $opcId,
                        'opc_bot_requerido' => false,
                        'opc_bot_orden' => 0,
                        'opc_bot_activo' => true,
                        'opc_bot_cre' => now()
                        // ❌ NO incluir opc_bot_edi porque no existe
                    ]
                ];

            default:
                throw new \Exception("Tipo de módulo no válido: {$tipo}");
        }
    }

    /**
     * ✅ MÉTODO AUXILIAR: Verificar y crear relaciones básicas de botones
     */
    private function verificarYCrearRelacionesBotones($configuracion)
    {
        try {
            $tabla = $configuracion['tabla_relaciones'];
            $campo = $configuracion['campo_modulo'];
            $valor = $configuracion['valor_modulo'];

            // Verificar si ya tiene botones asignados
            $tieneBotones = DB::table($tabla)
                ->where($campo, $valor)
                ->exists();

            if (!$tieneBotones) {
                Log::info("🔧 Creando relaciones básicas de botones para {$configuracion['tipo']}");

                // Obtener botones CRUD básicos
                $botonesCrud = DB::table('tbl_bot')
                    ->where('bot_activo', true)
                    ->whereIn('bot_codigo', ['CREATE', 'READ', 'UPDATE', 'DELETE', 'EXPORT'])
                    ->orderBy('bot_orden')
                    ->get();

                $relacionesCreadas = 0;
                foreach ($botonesCrud as $index => $boton) {
                    try {
                        $datosInsercion = $configuracion['campos_insercion'];
                        $datosInsercion['bot_id'] = $boton->bot_id;

                        // Actualizar orden
                        $campoOrden = $configuracion['tipo'] . '_bot_orden';
                        if (isset($datosInsercion[$campoOrden])) {
                            $datosInsercion[$campoOrden] = $index + 1;
                        }

                        DB::table($tabla)->insert($datosInsercion);
                        $relacionesCreadas++;

                        Log::info("✅ Relación de botón creada: {$boton->bot_codigo}");
                    } catch (\Exception $e) {
                        Log::warning("⚠️ Error creando relación para botón {$boton->bot_codigo}: " . $e->getMessage());
                        // Continuar con el siguiente
                    }
                }

                Log::info("✅ Relaciones básicas creadas: {$relacionesCreadas}");
            } else {
                Log::info("ℹ️ El módulo ya tiene botones asignados");
            }

        } catch (\Exception $e) {
            Log::error("❌ Error verificando/creando relaciones de botones", [
                'configuracion' => $configuracion,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * ✅ MÉTODO AUXILIAR: Obtener botones del módulo
     */
    private function obtenerBotonesDelModulo($configuracion)
    {
        return DB::table('tbl_bot as b')
            ->join($configuracion['tabla_relaciones'] . ' as rel', 'b.bot_id', '=', 'rel.bot_id')
            ->where('rel.' . $configuracion['campo_modulo'], $configuracion['valor_modulo'])
            ->where('rel.' . $configuracion['campo_activo'], true)
            ->where('b.bot_activo', true)
            ->select('b.*')
            ->orderBy('rel.' . str_replace('_activo', '_orden', $configuracion['campo_activo']))
            ->get();
    }

    /**
     * ✅ MÉTODO CORREGIDO: Revocar acceso completo
     */
    private function revocarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo)
    {
        try {
            Log::info("🗑️ Revocando acceso completo", [
                'perfil_id' => $perfilId,
                'modulo' => ['men_id' => $menId, 'sub_id' => $subId, 'opc_id' => $opcId],
                'tipo' => $tipo
            ]);

            // PASO 1: Eliminar permisos de botones
            $botonesRevocados = DB::table('tbl_perm_bot_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->delete();

            // PASO 2: Eliminar permiso de módulo
            $moduloRevocado = DB::table('tbl_perm_perfil')
                ->where('per_id', $perfilId)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->delete();

            Log::info("✅ Acceso revocado", [
                'botones_eliminados' => $botonesRevocados,
                'modulo_eliminado' => $moduloRevocado
            ]);

            return [
                'message' => "Acceso revocado correctamente ({$botonesRevocados} botones eliminados)",
                'data' => [
                    'permisos_revocados' => $botonesRevocados + $moduloRevocado,
                    'botones_eliminados' => $botonesRevocados,
                    'modulo_eliminado' => $moduloRevocado
                ]
            ];

        } catch (\Exception $e) {
            Log::error("❌ Error revocando acceso", [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    // ============== MÉTODOS EXISTENTES (mantener igual) ==============

    public function asignacionMasiva(Request $request, $perfilId)
    {
        $validator = Validator::make($request->all(), [
            'accion' => 'required|in:otorgar_todos,revocar_todos,solo_lectura',
            'modulos_especificos' => 'array',
            'modulos_especificos.*.men_id' => 'nullable|integer',
            'modulos_especificos.*.sub_id' => 'nullable|integer',
            'modulos_especificos.*.opc_id' => 'nullable|integer',
            'modulos_especificos.*.tipo' => 'required_with:modulos_especificos|in:menu,submenu,opcion'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $accion = $request->accion;
            $modulosEspecificos = $request->modulos_especificos ?? [];

            $estadisticas = [
                'modulos_procesados' => 0,
                'permisos_creados' => 0,
                'permisos_revocados' => 0,
                'errores' => []
            ];

            if ($accion === 'otorgar_todos') {
                // ✅ USAR MÉTODO CORREGIDO
                $modulosDirectos = $this->getTodosLosModulosDirectos();

                foreach ($modulosDirectos as $modulo) {
                    try {
                        $result = $this->otorgarAccesoCompletoCorregido(
                            $perfilId,
                            $modulo['men_id'],
                            $modulo['sub_id'],
                            $modulo['opc_id'],
                            $modulo['tipo']
                        );
                        $estadisticas['modulos_procesados']++;
                        $estadisticas['permisos_creados'] += $result['data']['botones_asignados'];
                    } catch (\Exception $e) {
                        $estadisticas['errores'][] = "Error en {$modulo['nombre']}: " . $e->getMessage();
                    }
                }
            } elseif ($accion === 'revocar_todos') {
                // Revocar todos los accesos
                $permisosRevocados = DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')
                            ->from('tbl_men')
                            ->where('men_ventana_directa', true);
                    })
                    ->delete();

                $permisosPerfilRevocados = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfilId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')
                            ->from('tbl_men')
                            ->where('men_ventana_directa', true);
                    })
                    ->delete();

                $estadisticas['permisos_revocados'] = $permisosRevocados + $permisosPerfilRevocados;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Asignación masiva completada: {$accion}",
                'estadisticas' => $estadisticas
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en asignación masiva: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error en asignación masiva: ' . $e->getMessage()
            ], 500);
        }
    }

    // ... resto de métodos existentes (mantener igual)
    private function getModulosDirectosConPermisos($perfilId)
    {
        Log::info("🔍 Obteniendo módulos directos con permisos para perfil: {$perfilId}");

        $modulos = [];

        // Obtener todos los permisos activos del perfil para módulos directos
        $permisosDirectos = DB::select("
        SELECT DISTINCT
            pp.men_id,
            pp.sub_id,
            pp.opc_id,
            m.men_nom,
            m.men_componente,
            m.men_ventana_directa,
            s.sub_nom,
            s.sub_componente,
            s.sub_ventana_directa,
            o.opc_nom,
            o.opc_componente,
            o.opc_ventana_directa,
            CASE 
                WHEN pp.opc_id IS NOT NULL AND o.opc_ventana_directa = true THEN 'opcion'
                WHEN pp.sub_id IS NOT NULL AND pp.opc_id IS NULL AND s.sub_ventana_directa = true THEN 'submenu'
                WHEN pp.sub_id IS NULL AND pp.opc_id IS NULL AND m.men_ventana_directa = true THEN 'menu'
                ELSE NULL
            END as tipo
        FROM tbl_perm_perfil pp
        JOIN tbl_men m ON pp.men_id = m.men_id
        LEFT JOIN tbl_sub s ON pp.sub_id = s.sub_id
        LEFT JOIN tbl_opc o ON pp.opc_id = o.opc_id
        WHERE pp.per_id = ?
          AND pp.perm_per_activo = true
          AND m.men_activo = true
          AND (s.sub_id IS NULL OR s.sub_activo = true)
          AND (o.opc_id IS NULL OR o.opc_activo = true)
          AND (
              (pp.opc_id IS NOT NULL AND o.opc_ventana_directa = true) OR
              (pp.sub_id IS NOT NULL AND pp.opc_id IS NULL AND s.sub_ventana_directa = true) OR
              (pp.sub_id IS NULL AND pp.opc_id IS NULL AND m.men_ventana_directa = true)
          )
        ORDER BY m.men_nom, s.sub_nom, o.opc_nom
    ", [$perfilId]);

        foreach ($permisosDirectos as $permiso) {
            if (!$permiso->tipo) continue;

            $tieneAcceso = $this->verificarAccesoCompleto(
                $perfilId,
                $permiso->men_id,
                $permiso->sub_id,
                $permiso->opc_id,
                $permiso->tipo
            );

            $nombre = $permiso->men_nom;
            if ($permiso->sub_nom) {
                $nombre .= ' > ' . $permiso->sub_nom;
            }
            if ($permiso->opc_nom) {
                $nombre .= ' > ' . $permiso->opc_nom;
            }

            $componente = $permiso->opc_componente ?? $permiso->sub_componente ?? $permiso->men_componente;

            $modulos[] = [
                'men_id' => $permiso->men_id,
                'sub_id' => $permiso->sub_id,
                'opc_id' => $permiso->opc_id,
                'tipo' => $permiso->tipo,
                'nombre' => $nombre,
                'componente' => $componente,
                'tiene_acceso' => true,
                'tiene_botones_configurados' => $tieneAcceso['botones_configurados'] > 0,
                'botones_con_permiso' => $tieneAcceso['botones_con_permiso'],
                'botones_configurados' => $tieneAcceso['botones_configurados']
            ];
        }

        Log::info("✅ Módulos directos encontrados para perfil {$perfilId}: " . count($modulos));

        return $modulos;
    }

    private function verificarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo)
    {
        // Verificar permiso de perfil
        $tienePermisoProfile = DB::table('tbl_perm_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('perm_per_activo', true)
            ->exists();

        // Contar botones configurados y con permiso
        $configuracion = $this->getConfiguracionPorTipo($tipo, $menId, $subId, $opcId);
        
        $botonesConfigurados = DB::table($configuracion['tabla_relaciones'])
            ->where($configuracion['campo_modulo'], $configuracion['valor_modulo'])
            ->where($configuracion['campo_activo'], true)
            ->count();

        $botonesConPermiso = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('perm_bot_per_activo', true)
            ->count();

        return [
            'tiene_acceso' => $tienePermisoProfile,
            'botones_configurados' => $botonesConfigurados,
            'botones_con_permiso' => $botonesConPermiso
        ];
    }

    private function getTodosLosModulosDirectos()
    {
        $modulos = [];

        // 1. Menús directos
        $menus = DB::table('tbl_men')
            ->where('men_ventana_directa', true)
            ->where('men_activo', true)
            ->get();

        foreach ($menus as $menu) {
            $modulos[] = [
                'men_id' => $menu->men_id,
                'sub_id' => null,
                'opc_id' => null,
                'tipo' => 'menu',
                'nombre' => $menu->men_nom
            ];
        }

        // 2. Submenús directos
        $submenus = DB::table('tbl_sub')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->where('tbl_sub.sub_ventana_directa', true)
            ->where('tbl_sub.sub_activo', true)
            ->get();

        foreach ($submenus as $submenu) {
            $modulos[] = [
                'men_id' => $submenu->men_id,
                'sub_id' => $submenu->sub_id,
                'opc_id' => null,
                'tipo' => 'submenu',
                'nombre' => $submenu->sub_nom
            ];
        }

        // 3. Opciones directas
        $opciones = DB::table('tbl_opc')
            ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
            ->join('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->where('tbl_opc.opc_ventana_directa', true)
            ->where('tbl_opc.opc_activo', true)
            ->select('tbl_opc.*', 'tbl_sub.sub_id', 'tbl_men_sub.men_id')
            ->get();

        foreach ($opciones as $opcion) {
            $modulos[] = [
                'men_id' => $opcion->men_id,
                'sub_id' => $opcion->sub_id,
                'opc_id' => $opcion->opc_id,
                'tipo' => 'opcion',
                'nombre' => $opcion->opc_nom
            ];
        }

        return $modulos;
    }

    public function copiarConfiguracion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'perfil_origen_id' => 'required|integer|exists:tbl_per,per_id',
            'perfil_destino_id' => 'required|integer|exists:tbl_per,per_id',
            'sobrescribir' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $perfilOrigenId = $request->perfil_origen_id;
            $perfilDestinoId = $request->perfil_destino_id;
            $sobrescribir = $request->sobrescribir ?? false;

            if ($perfilOrigenId === $perfilDestinoId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El perfil origen y destino no pueden ser el mismo'
                ], 422);
            }

            // Si se especifica sobrescribir, limpiar configuración existente
            if ($sobrescribir) {
                DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilDestinoId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')->from('tbl_men')->where('men_ventana_directa', true);
                    })
                    ->delete();

                DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfilDestinoId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')->from('tbl_men')->where('men_ventana_directa', true);
                    })
                    ->delete();
            }

            // Copiar permisos de perfil para módulos directos
            $permisosModulosCopiados = 0;
            $permisosModulos = DB::table('tbl_perm_perfil as pp')
                ->join('tbl_men as m', 'pp.men_id', '=', 'm.men_id')
                ->leftJoin('tbl_sub as s', 'pp.sub_id', '=', 's.sub_id')
                ->leftJoin('tbl_opc as o', 'pp.opc_id', '=', 'o.opc_id')
                ->where('pp.per_id', $perfilOrigenId)
                ->where('pp.perm_per_activo', true)
                ->where(function($query) {
                    $query->where(function($q) {
                        // Menú directo
                        $q->where('m.men_ventana_directa', true)
                          ->whereNull('pp.sub_id')
                          ->whereNull('pp.opc_id');
                    })->orWhere(function($q) {
                        // Submenú directo
                        $q->where('s.sub_ventana_directa', true)
                          ->whereNotNull('pp.sub_id')
                          ->whereNull('pp.opc_id');
                    })->orWhere(function($q) {
                        // Opción directa
                        $q->where('o.opc_ventana_directa', true)
                          ->whereNotNull('pp.opc_id');
                    });
                })
                ->select('pp.men_id', 'pp.sub_id', 'pp.opc_id')
                ->get();

            foreach ($permisosModulos as $permiso) {
                DB::table('tbl_perm_perfil')->updateOrInsert(
                    [
                        'per_id' => $perfilDestinoId,
                        'men_id' => $permiso->men_id,
                        'sub_id' => $permiso->sub_id,
                        'opc_id' => $permiso->opc_id
                    ],
                    [
                        'perm_per_activo' => true,
                        'perm_per_cre' => now(),
                        'perm_per_edi' => now()
                    ]
                );
                $permisosModulosCopiados++;
            }

            // Copiar permisos de botones para módulos directos
            $permisosBotonesCopiados = 0;
            $permisosBotones = DB::table('tbl_perm_bot_perfil as pbp')
                ->join('tbl_men as m', 'pbp.men_id', '=', 'm.men_id')
                ->leftJoin('tbl_sub as s', 'pbp.sub_id', '=', 's.sub_id')
                ->leftJoin('tbl_opc as o', 'pbp.opc_id', '=', 'o.opc_id')
                ->where('pbp.per_id', $perfilOrigenId)
                ->where('pbp.perm_bot_per_activo', true)
                ->where(function($query) {
                    $query->where(function($q) {
                        // Menú directo
                        $q->where('m.men_ventana_directa', true)
                          ->whereNull('pbp.sub_id')
                          ->whereNull('pbp.opc_id');
                    })->orWhere(function($q) {
                        // Submenú directo
                        $q->where('s.sub_ventana_directa', true)
                          ->whereNotNull('pbp.sub_id')
                          ->whereNull('pbp.opc_id');
                    })->orWhere(function($q) {
                        // Opción directa
                        $q->where('o.opc_ventana_directa', true)
                          ->whereNotNull('pbp.opc_id');
                    });
                })
                ->select('pbp.men_id', 'pbp.sub_id', 'pbp.opc_id', 'pbp.bot_id')
                ->get();

            foreach ($permisosBotones as $permiso) {
                DB::table('tbl_perm_bot_perfil')->updateOrInsert(
                    [
                        'per_id' => $perfilDestinoId,
                        'men_id' => $permiso->men_id,
                        'sub_id' => $permiso->sub_id,
                        'opc_id' => $permiso->opc_id,
                        'bot_id' => $permiso->bot_id
                    ],
                    [
                        'perm_bot_per_activo' => true,
                        'perm_bot_per_cre' => now(),
                        'perm_bot_per_edi' => now()
                    ]
                );
                $permisosBotonesCopiados++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración copiada correctamente',
                'data' => [
                    'perfil_origen' => $perfilOrigenId,
                    'perfil_destino' => $perfilDestinoId,
                    'sobrescribio' => $sobrescribir,
                    'modulos_copiados' => $permisosModulosCopiados,
                    'botones_copiados' => $permisosBotonesCopiados
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error copiando configuración: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar configuración: ' . $e->getMessage()
            ], 500);
        }
    }
}