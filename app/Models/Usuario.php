<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory;

    protected $table = 'tbl_usu';
    protected $primaryKey = 'usu_id';
    public $timestamps = false; // Usamos campos personalizados
    
    protected $fillable = [
        'usu_nom', 'usu_nom2', 'usu_ape', 'usu_ape2', 'usu_cor', 
        'usu_ced', 'usu_con', 'usu_tel', 'usu_dir', 'per_id', 'est_id',
        'usu_descripcion', 'usu_fecha_nacimiento', 'usu_fecha_registro',
        'usu_fecha_actualizacion_clave', 'usu_fecha_cambio_clave',
        'usu_deshabilitado', 'usu_clave_hasheada', 'usu_nombre_encriptado',
        'usu_ultimo_acceso', 'usu_intentos_fallidos', 'usu_bloqueado_hasta',
        'usu_cre', 'usu_edi', 'usu_creado_por', 'usu_editado_por'
    ];

    protected $hidden = [
        'usu_con', 
        'usu_clave_hasheada',
        'usu_nombre_encriptado'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'usu_fecha_nacimiento' => 'date',
        'usu_fecha_registro' => 'datetime',
        'usu_ultimo_acceso' => 'datetime',
        'usu_bloqueado_hasta' => 'datetime',
        'usu_fecha_actualizacion_clave' => 'datetime',
        'usu_fecha_cambio_clave' => 'datetime',
        'usu_cre' => 'datetime',
        'usu_edi' => 'datetime',
        'usu_deshabilitado' => 'boolean',
        'usu_intentos_fallidos' => 'integer',
        'per_id' => 'integer',
        'est_id' => 'integer',
        'usu_creado_por' => 'integer',
        'usu_editado_por' => 'integer'
    ];

    // ==================== RELACIONES ====================

    /**
     * Relación con perfil
     */
    public function perfil()
    {
        return $this->belongsTo(Perfil::class, 'per_id', 'per_id');
    }

    /**
     * Relación con estado
     */
    public function estado()
    {
        return $this->belongsTo(Estado::class, 'est_id', 'est_id');
    }

    /**
     * Usuario que creó este registro
     */
    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'usu_creado_por', 'usu_id');
    }

    /**
     * Usuario que editó este registro
     */
    public function editadoPor()
    {
        return $this->belongsTo(Usuario::class, 'usu_editado_por', 'usu_id');
    }

    // ==================== AUTENTICACIÓN ====================

    /**
     * Get the password for authentication.
     */
    public function getAuthPassword()
    {
        return $this->usu_con;
    }

    /**
     * Get the email for password reset.
     */
    public function getEmailForPasswordReset()
    {
        return $this->usu_cor;
    }

    /**
     * Get the username field for authentication.
     */
    public function username()
    {
        return 'usu_cor';
    }

    /**
     * Get the email attribute for authentication.
     */
    public function getEmailAttribute()
    {
        return $this->usu_cor;
    }

    /**
     * Find the user instance for the given username (email).
     */
    public function findForPassport($username)
    {
        return $this->where('usu_cor', $username)->first();
    }

    // ==================== ACCESSORS ====================

    /**
     * Get the full name attribute.
     */
    public function getNombreCompletoAttribute()
    {
        $nombre = trim($this->usu_nom . ' ' . ($this->usu_nom2 ?? ''));
        $apellido = trim($this->usu_ape . ' ' . ($this->usu_ape2 ?? ''));
        return trim($nombre . ' ' . $apellido);
    }

    /**
     * Get the name attribute (for compatibility).
     */
    public function getNameAttribute()
    {
        return $this->nombre_completo;
    }

    // ==================== SCOPES ====================

    /**
     * Scope para usuarios activos (no deshabilitados)
     */
    public function scopeActivos($query)
    {
        return $query->where('usu_deshabilitado', false);
    }

    /**
     * Scope para usuarios por perfil
     */
    public function scopePorPerfil($query, $perfilId)
    {
        return $query->where('per_id', $perfilId);
    }

    /**
     * Scope para usuarios por estado
     */
    public function scopePorEstado($query, $estadoId)
    {
        return $query->where('est_id', $estadoId);
    }

    /**
     * Scope para buscar usuarios
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where(function($q) use ($termino) {
            $q->where('usu_nom', 'ILIKE', "%{$termino}%")
              ->orWhere('usu_nom2', 'ILIKE', "%{$termino}%")
              ->orWhere('usu_ape', 'ILIKE', "%{$termino}%")
              ->orWhere('usu_ape2', 'ILIKE', "%{$termino}%")
              ->orWhere('usu_cor', 'ILIKE', "%{$termino}%")
              ->orWhere('usu_ced', 'ILIKE', "%{$termino}%");
        });
    }

    // ==================== MÉTODOS DE ESTADO ====================

    /**
     * Verificar si el usuario está bloqueado
     */
    public function estaBloqueado()
    {
        return $this->usu_bloqueado_hasta && $this->usu_bloqueado_hasta > now();
    }

    /**
     * Verificar si el usuario está deshabilitado
     */
    public function estaDeshabilitado()
    {
        return $this->usu_deshabilitado;
    }

    /**
     * Verificar si el usuario puede loguearse
     */
    public function puedeLoguear()
    {
        return !$this->estaDeshabilitado() && !$this->estaBloqueado();
    }

    /**
     * Verificar si el usuario está activo
     */
    public function estaActivo()
    {
        return !$this->usu_deshabilitado;
    }

    // ==================== MÉTODOS DE UTILIDAD ====================

    /**
     * Incrementar intentos fallidos
     */
    public function incrementarIntentosFallidos()
    {
        $this->increment('usu_intentos_fallidos');
        
        // Bloquear después de 5 intentos fallidos por 30 minutos
        if ($this->usu_intentos_fallidos >= 5) {
            $this->update([
                'usu_bloqueado_hasta' => now()->addMinutes(30)
            ]);
        }
    }

    /**
     * Resetear intentos fallidos
     */
    public function resetearIntentosFallidos()
    {
        $this->update([
            'usu_intentos_fallidos' => 0,
            'usu_bloqueado_hasta' => null,
            'usu_ultimo_acceso' => now()
        ]);
    }

    /**
     * Obtener información básica del usuario
     */
    public function getInfoBasica()
    {
        return [
            'usu_id' => $this->usu_id,
            'nombre_completo' => $this->nombre_completo,
            'usu_cor' => $this->usu_cor,
            'usu_ced' => $this->usu_ced,
            'perfil' => $this->perfil?->per_nom,
            'estado' => $this->estado?->est_nom,
            'activo' => $this->estaActivo(),
            'bloqueado' => $this->estaBloqueado()
        ];
    }

    /**
     * Verificar si la contraseña necesita ser cambiada
     */
    public function necesitaCambiarClave()
    {
        // Si nunca ha cambiado la contraseña o hace más de 90 días
        if (!$this->usu_fecha_cambio_clave) {
            return true;
        }
        
        return $this->usu_fecha_cambio_clave->diffInDays(now()) > 90;
    }

    /**
     * Marcar último acceso
     */
    public function marcarUltimoAcceso()
    {
        $this->update([
            'usu_ultimo_acceso' => now()
        ]);
    }

    // ==================== EVENTOS DEL MODELO ====================

    /**
     * Boot method for model events
     */
    protected static function boot()
    {
        parent::boot();

        // Evento al crear un usuario
        static::creating(function ($usuario) {
            $usuario->usu_fecha_registro = $usuario->usu_fecha_registro ?? now();
            $usuario->usu_cre = now();
            $usuario->usu_edi = now();
            $usuario->usu_deshabilitado = $usuario->usu_deshabilitado ?? false;
            $usuario->usu_intentos_fallidos = $usuario->usu_intentos_fallidos ?? 0;
        });

        // Evento al actualizar un usuario
        static::updating(function ($usuario) {
            $usuario->usu_edi = now();
        });
    }
}