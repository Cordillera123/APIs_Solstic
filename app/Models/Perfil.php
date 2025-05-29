<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class Perfil extends Model
{
    use HasFactory;

    protected $table = 'tbl_per';
    protected $primaryKey = 'per_id';
    public $timestamps = false; // Usamos campos personalizados
    
    protected $fillable = [
        'per_nom',
        'per_descripcion',
        'per_nivel',
        'per_activo',
        'per_cre',
        'per_edi'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'per_nivel' => 'integer',
        'per_activo' => 'boolean',
        'per_cre' => 'datetime',
        'per_edi' => 'datetime'
    ];

    // ==================== RELACIONES ====================

    /**
     * Usuarios que tienen este perfil
     */
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'per_id', 'per_id');
    }

    /**
     * Usuarios activos con este perfil
     */
    public function usuariosActivos()
    {
        return $this->hasMany(Usuario::class, 'per_id', 'per_id')
                    ->where('usu_deshabilitado', false);
    }

    // ==================== SCOPES ====================

    /**
     * Scope para perfiles activos
     */
    public function scopeActivos($query)
    {
        return $query->where('per_activo', true);
    }

    /**
     * Scope para perfiles por nivel
     */
    public function scopePorNivel($query, $nivel)
    {
        return $query->where('per_nivel', $nivel);
    }

    /**
     * Scope para buscar perfiles
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where(function($q) use ($termino) {
            $q->where('per_nom', 'ILIKE', "%{$termino}%")
              ->orWhere('per_descripcion', 'ILIKE', "%{$termino}%");
        });
    }

    /**
     * Scope para ordenar por nivel y nombre
     */
    public function scopeOrdenadoPorNivel($query)
    {
        return $query->orderBy('per_nivel', 'asc')
                     ->orderBy('per_nom', 'asc');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get the level description attribute.
     */
    public function getNivelDescripcionAttribute()
    {
        $niveles = [
            1 => 'Básico',
            2 => 'Usuario',
            3 => 'Operador',
            4 => 'Supervisor',
            5 => 'Coordinador',
            6 => 'Jefe',
            7 => 'Gerente',
            8 => 'Director',
            9 => 'Administrador',
            10 => 'Super Administrador'
        ];

        return $niveles[$this->per_nivel] ?? "Nivel {$this->per_nivel}";
    }

    /**
     * Get the status text attribute.
     */
    public function getEstadoTextoAttribute()
    {
        return $this->per_activo ? 'Activo' : 'Inactivo';
    }

    // ==================== MÉTODOS DE UTILIDAD ====================

    /**
     * Verificar si el perfil está activo
     */
    public function estaActivo()
    {
        return $this->per_activo;
    }

    /**
     * Obtener el número de usuarios asignados
     */
    public function getTotalUsuarios()
    {
        return $this->usuarios()->count();
    }

    /**
     * Obtener el número de usuarios activos asignados
     */
    public function getTotalUsuariosActivos()
    {
        return $this->usuariosActivos()->count();
    }

    /**
     * Verificar si se puede eliminar el perfil
     */
    public function puedeEliminarse()
    {
        return $this->getTotalUsuarios() === 0;
    }

    /**
     * Obtener información básica del perfil
     */
    public function getInfoBasica()
    {
        return [
            'per_id' => $this->per_id,
            'per_nom' => $this->per_nom,
            'per_descripcion' => $this->per_descripcion,
            'per_nivel' => $this->per_nivel,
            'nivel_descripcion' => $this->nivel_descripcion,
            'per_activo' => $this->per_activo,
            'estado_texto' => $this->estado_texto,
            'total_usuarios' => $this->getTotalUsuarios(),
            'total_usuarios_activos' => $this->getTotalUsuariosActivos(),
            'puede_eliminarse' => $this->puedeEliminarse()
        ];
    }

    /**
     * Activar perfil
     */
    public function activar()
    {
        $this->update([
            'per_activo' => true,
            'per_edi' => Carbon::now()
        ]);
    }

    /**
     * Desactivar perfil
     */
    public function desactivar()
    {
        $this->update([
            'per_activo' => false,
            'per_edi' => Carbon::now()
        ]);
    }

    // ==================== EVENTOS DEL MODELO ====================

    /**
     * Boot method for model events
     */
    protected static function boot()
    {
        parent::boot();

        // Evento al crear un perfil
        static::creating(function ($perfil) {
            $perfil->per_cre = $perfil->per_cre ?? Carbon::now();
            $perfil->per_edi = Carbon::now();
            $perfil->per_activo = $perfil->per_activo ?? true;
            $perfil->per_nivel = $perfil->per_nivel ?? 1;
        });

        // Evento al actualizar un perfil
        static::updating(function ($perfil) {
            $perfil->per_edi = Carbon::now();
        });
    }
}