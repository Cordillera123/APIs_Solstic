<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LogoController extends Controller
{
    /**
     * Obtener todos los logos activos
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Logo::activos()
                ->with('usuarioSubida:usu_id,usu_nom,usu_ape,usu_cor')
                ->ordenados();

            // Filtrar por ubicación si se especifica
            if ($request->has('ubicacion')) {
                $query->porUbicacion($request->ubicacion);
            }

            // Filtrar solo principales si se especifica
            if ($request->boolean('solo_principales')) {
                $query->principales();
            }

            $logos = $query->get();

            return response()->json([
                'status' => 'success',
                'data' => $logos,
                'total' => $logos->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener logos: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener logos'
            ], 500);
        }
    }

    /**
     * Obtener logos por ubicación específica
     */
    public function getByUbicacion($ubicacion): JsonResponse
    {
        try {
            // Validar ubicación
            if (!in_array($ubicacion, Logo::UBICACIONES_VALIDAS)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ubicación no válida'
                ], 400);
            }

            // Obtener logo principal de la ubicación
            $logoPrincipal = Logo::obtenerLogoPrincipal($ubicacion);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'ubicacion' => $ubicacion,
                    'logo' => $logoPrincipal
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error al obtener logo por ubicación {$ubicacion}: " . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener logo'
            ], 500);
        }
    }

    /**
     * Obtener todos los logos organizados por ubicación
     */
    public function getAllByUbicacion(): JsonResponse
    {
        try {
            $logosPorUbicacion = [];

            foreach (Logo::UBICACIONES_VALIDAS as $ubicacion) {
                $logosPorUbicacion[$ubicacion] = Logo::obtenerLogoPrincipal($ubicacion);
            }

            return response()->json([
                'status' => 'success',
                'data' => $logosPorUbicacion
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener logos por ubicación: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener logos'
            ], 500);
        }
    }

    /**
     * Subir y almacenar un nuevo logo
     */
    // REEMPLAZA el método store() en tu LogoController.php con esta versión:

public function store(Request $request): JsonResponse
{
    try {
        Log::info('LogoController store iniciado');

        // Validar permisos
        if (!$this->verificarPermisos()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tiene permisos para subir logos'
            ], 403);
        }

        // Validación
        $validator = Validator::make($request->all(), [
            'logo' => 'required|file|mimes:jpeg,png,gif,webp|max:2048',
            'ubicacion' => 'required|in:' . implode(',', Logo::UBICACIONES_VALIDAS),
            'nombre' => 'nullable|string|max:100',
            'descripcion' => 'nullable|string|max:255',
            'establecer_principal' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            Log::error('Validación fallida en store');
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $archivo = $request->file('logo');
        $ubicacion = $request->input('ubicacion', 'general');

        Log::info('Procesando archivo de logo');

        // Validar archivo
        $erroresArchivo = Logo::validarArchivo($archivo);
        if (!empty($erroresArchivo)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo no válido',
                'errors' => $erroresArchivo
            ], 422);
        }

        // ✅ NUEVO: Eliminar logo anterior de la misma ubicación
        $logoAnterior = Logo::activos()
            ->principales()
            ->porUbicacion($ubicacion)
            ->first();

        if ($logoAnterior) {
            Log::info('Eliminando logo anterior de ubicacion: ' . $ubicacion);
            
            // Eliminar archivo físico anterior
            if ($logoAnterior->logo_url && Storage::disk('public')->exists($logoAnterior->logo_url)) {
                Storage::disk('public')->delete($logoAnterior->logo_url);
                Log::info('Archivo anterior eliminado: ' . $logoAnterior->logo_url);
            }
            
            // Eliminar registro de base de datos
            $logoAnterior->delete();
            Log::info('Registro anterior eliminado de base de datos');
        }

        // Obtener dimensiones de la imagen
        $dimensiones = getimagesize($archivo->getPathname());
        list($ancho, $alto) = $dimensiones ?: [0, 0];

        // Generar nombre único y almacenar archivo
        $nombreArchivo = Logo::generarNombreArchivo($archivo, $ubicacion);
        
        Log::info('Almacenando archivo: ' . $nombreArchivo);

        // Crear directorio y almacenar
        $directorioUbicacion = "logos/{$ubicacion}";
        
        // Asegurar que el directorio existe
        if (!Storage::disk('public')->exists($directorioUbicacion)) {
            Storage::disk('public')->makeDirectory($directorioUbicacion);
        }
        
        // Almacenar el archivo
        $rutaArchivo = Storage::disk('public')->putFileAs(
            $directorioUbicacion, 
            $archivo, 
            basename($nombreArchivo)
        );

        if (!$rutaArchivo) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al almacenar el archivo'
            ], 500);
        }

        Log::info('Archivo almacenado correctamente');

        // Crear registro en base de datos
        $logo = Logo::create([
            'logo_nombre' => $request->input('nombre', pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME)),
            'logo_url' => $rutaArchivo,
            'logo_nombre_original' => $archivo->getClientOriginalName(),
            'logo_tamaño' => $archivo->getSize(),
            'logo_tipo' => $archivo->getMimeType(),
            'logo_ancho' => $ancho,
            'logo_alto' => $alto,
            'logo_ubicacion' => $ubicacion,
            'logo_descripcion' => $request->input('descripcion'),
            'logo_usuario_subida' => Auth::id(),
            'logo_principal' => true  // ✅ Siempre true ya que eliminamos el anterior
        ]);

        Log::info('Logo creado en base de datos con ID: ' . $logo->logo_id);

        // Establecer como principal (siempre, ya que es el único)
        $logo->establecerComoPrincipal();

        // Actualizar configuración
        $this->actualizarConfiguracion($ubicacion, $logo->logo_id);

        // Recargar el logo para obtener todos los campos calculados
        $logoCompleto = $logo->fresh()->load('usuarioSubida');

        Log::info('LogoController store completado exitosamente');

        return response()->json([
            'status' => 'success',
            'message' => 'Logo subido exitosamente',
            'data' => $logoCompleto
        ], 201);

    } catch (\Exception $e) {
        Log::error('Error en LogoController store: ' . $e->getMessage());
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error interno del servidor'
        ], 500);
    }
}
    /**
     * Mostrar un logo específico
     */
    public function show($id): JsonResponse
    {
        try {
            $logo = Logo::with('usuarioSubida:usu_id,usu_nom,usu_ape,usu_cor')
                ->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $logo
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Logo no encontrado'
            ], 404);
        }
    }

    /**
     * Actualizar un logo existente
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Validar permisos
            if (!$this->verificarPermisos()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tiene permisos para actualizar logos'
                ], 403);
            }

            $logo = Logo::findOrFail($id);

            // Validar entrada
            $validator = Validator::make($request->all(), [
                'nombre' => 'nullable|string|max:100',
                'descripcion' => 'nullable|string|max:255',
                'establecer_principal' => 'boolean',
                'activo' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ CORRECCIÓN: Actualizar campos correctamente
            $updateData = [];
            
            if ($request->has('nombre')) {
                $updateData['logo_nombre'] = $request->input('nombre');
            }
            
            if ($request->has('descripcion')) {
                $updateData['logo_descripcion'] = $request->input('descripcion');
            }
            
            if ($request->has('activo')) {
                $updateData['logo_activo'] = $request->boolean('activo');
            }
            
            // Solo actualizar si hay datos
            if (!empty($updateData)) {
                $logo->update($updateData);
            }

            // Establecer como principal si se especifica
            if ($request->boolean('establecer_principal')) {
                $logo->establecerComoPrincipal();
                $this->actualizarConfiguracion($logo->logo_ubicacion, $logo->logo_id);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Logo actualizado exitosamente',
                'data' => $logo->fresh()->load('usuarioSubida')
            ]);

        } catch (\Exception $e) {
            Log::error('Error al actualizar logo: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar logo'
            ], 500);
        }
    }

    /**
     * Eliminar un logo
     */
    public function destroy($id): JsonResponse
    {
        try {
            // Validar permisos
            if (!$this->verificarPermisos()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tiene permisos para eliminar logos'
                ], 403);
            }

            $logo = Logo::findOrFail($id);

            // No permitir eliminar el logo por defecto
            if ($logo->logo_id === 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el logo por defecto'
                ], 422);
            }

            $ubicacion = $logo->logo_ubicacion;
            $eraPrincipal = $logo->logo_principal;

            // Eliminar logo
            $logo->delete();

            // Si era principal, establecer otro como principal
            if ($eraPrincipal) {
                $nuevoLogoPrincipal = Logo::activos()
                    ->porUbicacion($ubicacion)
                    ->orderBy('logo_id', 'asc')
                    ->first();

                if ($nuevoLogoPrincipal) {
                    $nuevoLogoPrincipal->establecerComoPrincipal();
                    $this->actualizarConfiguracion($ubicacion, $nuevoLogoPrincipal->logo_id);
                } else {
                    // Si no hay otro logo, usar el logo general
                    $this->actualizarConfiguracion($ubicacion, 1);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Logo eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error al eliminar logo: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar logo'
            ], 500);
        }
    }

    /**
     * Establecer un logo como principal
     */
    public function setPrincipal($id): JsonResponse
    {
        try {
            // Validar permisos
            if (!$this->verificarPermisos()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tiene permisos para modificar logos'
                ], 403);
            }

            $logo = Logo::findOrFail($id);
            $logo->establecerComoPrincipal();

            // Actualizar configuración
            $this->actualizarConfiguracion($logo->logo_ubicacion, $logo->logo_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Logo establecido como principal',
                'data' => $logo
            ]);

        } catch (\Exception $e) {
            Log::error('Error al establecer logo principal: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al establecer logo principal'
            ], 500);
        }
    }

    /**
     * Obtener configuración de logos
     */
    public function getConfig(): JsonResponse
    {
        try {
            $config = Logo::obtenerConfiguracion();
            
            return response()->json([
                'status' => 'success',
                'data' => $config
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener configuración de logos: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener configuración'
            ], 500);
        }
    }

    /**
     * Verificar permisos del usuario para gestionar logos
     */
    private function verificarPermisos(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        // Verificar si el usuario tiene permisos de administrador
        // Aquí puedes implementar tu lógica de permisos específica
        // Por ejemplo, verificar si tiene un perfil específico o permisos especiales
        
        return true; // Temporal - implementar lógica real
    }

    /**
     * Actualizar configuración en tbl_config
     */
    private function actualizarConfiguracion(string $ubicacion, int $logoId): void
    {
        $configKey = match($ubicacion) {
            'login' => 'logo_login_id',
            'sidebar' => 'logo_sidebar_id',
            default => 'logo_principal_id'
        };

        DB::table('tbl_config')
            ->where('conf_nom', $configKey)
            ->update(['conf_detalle' => (string) $logoId]);
    }
    // AGREGA ESTE MÉTODO al final de tu LogoController.php (antes del último })

/**
 * Limpiar archivos huérfanos (archivos sin registro en BD)
 */
public function cleanupOrphanFiles(): JsonResponse
{
    try {
        $cleanedFiles = 0;
        
        foreach (Logo::UBICACIONES_VALIDAS as $ubicacion) {
            $directorioUbicacion = "logos/{$ubicacion}";
            
            if (Storage::disk('public')->exists($directorioUbicacion)) {
                $archivos = Storage::disk('public')->files($directorioUbicacion);
                
                foreach ($archivos as $archivo) {
                    // Verificar si existe en base de datos
                    $existeEnBD = Logo::where('logo_url', $archivo)->exists();
                    
                    if (!$existeEnBD && !str_contains($archivo, 'logo-default')) {
                        Storage::disk('public')->delete($archivo);
                        $cleanedFiles++;
                        Log::info('Archivo huérfano eliminado: ' . $archivo);
                    }
                }
            }
        }
        
        return response()->json([
            'status' => 'success',
            'message' => "Se eliminaron {$cleanedFiles} archivos huérfanos",
            'files_cleaned' => $cleanedFiles
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error en cleanup: ' . $e->getMessage());
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error en limpieza de archivos'
        ], 500);
    }
}
}