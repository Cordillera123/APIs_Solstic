<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Logo extends Model
{
    /**
     * Nombre de la tabla en la base de datos
     */
    protected $table = 'tbl_logos';

    /**
     * Clave primaria de la tabla
     */
    protected $primaryKey = 'logo_id';

    /**
     * Indica si el modelo debe manejar timestamps automáticamente
     */
    public $timestamps = false;

    /**
     * Campos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'logo_nombre',
        'logo_url',
        'logo_nombre_original',
        'logo_tamaño',
        'logo_tipo',
        'logo_ancho',
        'logo_alto',
        'logo_activo',
        'logo_principal',
        'logo_ubicacion',
        'logo_descripcion',
        'logo_usuario_subida',
        'logo_orden'
    ];

    /**
     * Campos que deben ser convertidos a tipos específicos
     */
    protected $casts = [
        'logo_activo' => 'boolean',
        'logo_principal' => 'boolean',
        'logo_tamaño' => 'integer',
        'logo_ancho' => 'integer',
        'logo_alto' => 'integer',
        'logo_orden' => 'integer',
        'logo_fecha_subida' => 'datetime',
        'logo_fecha_actualizacion' => 'datetime',
    ];

    /**
     * Valores por defecto para campos
     */
    protected $attributes = [
        'logo_activo' => true,
        'logo_principal' => false,
        'logo_ubicacion' => 'general',
        'logo_orden' => 0,
    ];

    /**
     * Campos que deben ser ocultados en las respuestas JSON
     */
    protected $hidden = [
        'logo_usuario_subida'
    ];

    /**
     * Campos adicionales que se incluyen en las respuestas JSON
     */
    protected $appends = [
        'logo_url_completa',
        'logo_tamaño_formateado',
        'logo_dimensiones'
    ];

    /**
     * Ubicaciones válidas para los logos
     */
    const UBICACIONES_VALIDAS = ['general', 'login', 'sidebar'];

    /**
     * Tipos de archivo permitidos
     */
    const TIPOS_PERMITIDOS = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Tamaño máximo en bytes (2MB)
     */
    const TAMAÑO_MAXIMO = 2097152;

    /**
     * Relación con el usuario que subió el logo
     */
    public function usuarioSubida(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'logo_usuario_subida', 'usu_id');
    }

    /**
     * Scope para obtener solo logos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('logo_activo', true);
    }

    /**
     * Scope para obtener logos principales
     */
    public function scopePrincipales($query)
    {
        return $query->where('logo_principal', true);
    }

    /**
     * Scope para obtener logos por ubicación
     */
    public function scopePorUbicacion($query, $ubicacion)
    {
        return $query->where('logo_ubicacion', $ubicacion);
    }

    /**
     * Scope para obtener logos ordenados
     */
    public function scopeOrdenados($query)
    {
        return $query->orderBy('logo_orden', 'asc')
                    ->orderBy('logo_fecha_subida', 'desc');
    }

    /**
     * Accessor para obtener la URL completa del logo
     */
    public function getLogoUrlCompletaAttribute()
    {
        if (str_starts_with($this->logo_url, 'http')) {
            return $this->logo_url;
        }
        
        return Storage::url($this->logo_url);
    }

    /**
     * Accessor para obtener el tamaño formateado
     */
    public function getLogoTamañoFormateadoAttribute()
    {
        if (!$this->logo_tamaño) {
            return null;
        }

        $bytes = $this->logo_tamaño;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Accessor para obtener las dimensiones formateadas
     */
    public function getLogoDimensionesAttribute()
    {
        if (!$this->logo_ancho || !$this->logo_alto) {
            return null;
        }
        
        return $this->logo_ancho . 'x' . $this->logo_alto . ' px';
    }

    /**
     * Método estático para obtener el logo principal de una ubicación
     */
    public static function obtenerLogoPrincipal($ubicacion = 'general')
    {
        $logo = static::activos()
            ->principales()
            ->porUbicacion($ubicacion)
            ->first();

        // Si no hay logo específico para la ubicación, usar el logo general
        if (!$logo && $ubicacion !== 'general') {
            $logo = static::activos()
                ->principales()
                ->porUbicacion('general')
                ->first();
        }

        return $logo;
    }

    /**
     * Método para establecer un logo como principal
     */
    public function establecerComoPrincipal()
    {
        // Desmarcar otros logos principales de la misma ubicación
        static::where('logo_ubicacion', $this->logo_ubicacion)
            ->where('logo_id', '!=', $this->logo_id)
            ->update(['logo_principal' => false]);

        // Marcar este logo como principal
        $this->update(['logo_principal' => true]);
    }

    /**
     * Método para eliminar el archivo físico del logo
     */
    public function eliminarArchivo()
    {
        if ($this->logo_url && Storage::exists($this->logo_url)) {
            return Storage::delete($this->logo_url);
        }
        
        return true;
    }

    /**
     * Método para validar el archivo antes de guardar
     */
    public static function validarArchivo($archivo)
    {
        $errores = [];

        // Validar que sea un archivo
        if (!$archivo || !$archivo->isValid()) {
            $errores[] = 'El archivo no es válido';
            return $errores;
        }

        // Validar tipo de archivo
        if (!in_array($archivo->getMimeType(), self::TIPOS_PERMITIDOS)) {
            $errores[] = 'Tipo de archivo no permitido. Use: ' . implode(', ', self::TIPOS_PERMITIDOS);
        }

        // Validar tamaño
        if ($archivo->getSize() > self::TAMAÑO_MAXIMO) {
            $errores[] = 'El archivo es demasiado grande. Máximo: ' . (self::TAMAÑO_MAXIMO / 1024 / 1024) . 'MB';
        }

        // Validar dimensiones si es imagen
        if (str_starts_with($archivo->getMimeType(), 'image/')) {
            $dimensiones = getimagesize($archivo->getPathname());
            if ($dimensiones) {
                list($ancho, $alto) = $dimensiones;
                
                if ($ancho > 1000 || $alto > 400) {
                    $errores[] = 'Las dimensiones son demasiado grandes. Máximo: 1000x400px';
                }
                
                if ($ancho < 50 || $alto < 20) {
                    $errores[] = 'Las dimensiones son demasiado pequeñas. Mínimo: 50x20px';
                }
            }
        }

        return $errores;
    }

    /**
     * Método para generar un nombre único para el archivo
     */
    public static function generarNombreArchivo($archivo, $ubicacion = 'general')
    {
        $extension = $archivo->getClientOriginalExtension();
        $nombreSinExtension = pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME);
        $nombreLimpio = preg_replace('/[^a-zA-Z0-9-_]/', '', $nombreSinExtension);
        
        // Generar nombre único
        return $ubicacion . '/' . $nombreLimpio . '_' . time() . '.' . $extension;
    }

    /**
     * Método para obtener información de configuración desde tbl_config
     */
    public static function obtenerConfiguracion()
    {
        $config = DB::table('tbl_config')
            ->whereIn('conf_nom', [
                'logo_config_limites',
                'logo_storage_config',
                'logo_permissions_config'
            ])
            ->pluck('conf_detalle', 'conf_nom');

        return [
            'limites' => $config->get('logo_config_limites') ? json_decode($config->get('logo_config_limites'), true) : [],
            'storage' => $config->get('logo_storage_config') ? json_decode($config->get('logo_storage_config'), true) : [],
            'permisos' => $config->get('logo_permissions_config') ? json_decode($config->get('logo_permissions_config'), true) : []
        ];
    }

    /**
     * Boot method para eventos del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Evento antes de crear
        static::creating(function ($logo) {
            $logo->logo_fecha_subida = now();
            $logo->logo_fecha_actualizacion = now();
        });

        // Evento antes de actualizar
        static::updating(function ($logo) {
            $logo->logo_fecha_actualizacion = now();
        });

        // Evento antes de eliminar
        static::deleting(function ($logo) {
            $logo->eliminarArchivo();
        });
    }
}