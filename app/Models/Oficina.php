<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Oficina extends Model
{
    use HasFactory;

    protected $table = 'gaf_oficin';
    protected $primaryKey = 'oficin_codigo';
    public $timestamps = false; // Usamos campos personalizados

    protected $fillable = [
        'oficin_nombre',
        'oficin_instit_codigo',
        'oficin_tofici_codigo',
        'oficin_parroq_codigo',
        'oficin_direccion',
        'oficin_telefono',
        'oficin_diremail',
        'oficin_codocntrl',
        'oficin_ctractual',
        'oficin_eregis_codigo',
        'oficin_rucoficina',
        'oficin_codresapertura',
        'oficin_fechaapertura',
        'oficin_fechacierre',
        'oficin_codrescierre',
        'oficin_fecharescierre'
    ];

    protected $casts = [
        'oficin_fechaapertura' => 'date',
        'oficin_fechacierre' => 'date',
        'oficin_codrescierre' => 'date',
        'oficin_fecharescierre' => 'date',
        'oficin_ctractual' => 'integer',
        'oficin_instit_codigo' => 'integer',
        'oficin_tofici_codigo' => 'integer',
        'oficin_parroq_codigo' => 'integer',
        'oficin_eregis_codigo' => 'integer'
    ];

    // ==================== RELACIONES ====================

    /**
     * Relación con institución
     */
    public function institucion()
    {
        return $this->belongsTo(Institucion::class, 'oficin_instit_codigo', 'instit_codigo');
    }

    /**
     * Relación con tipo de oficina (COMPATIBLE CON TipoOficinaController)
     */
    public function tipoOficina()
    {
        return $this->belongsTo(TipoOficina::class, 'oficin_tofici_codigo', 'tofici_codigo');
    }

    /**
     * Relación con parroquia
     */
    public function parroquia()
    {
        return $this->belongsTo(Parroquia::class, 'oficin_parroq_codigo', 'parroq_codigo');
    }

    /**
     * Relación con estado de registro
     */
    public function estadoRegistro()
    {
        return $this->belongsTo(EstadoRegistro::class, 'oficin_eregis_codigo', 'eregis_codigo');
    }

    /**
     * Usuarios asignados a esta oficina
     */
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'oficin_codigo', 'oficin_codigo');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get the full address attribute.
     */
    public function getDireccionCompletaAttribute()
    {
        $direccion = $this->oficin_direccion;
        if ($this->parroquia) {
            $direccion .= ', ' . $this->parroquia->parroq_nombre;
            if ($this->parroquia->canton) {
                $direccion .= ', ' . $this->parroquia->canton->canton_nombre;
                if ($this->parroquia->canton->provincia) {
                    $direccion .= ', ' . $this->parroquia->canton->provincia->provin_nombre;
                }
            }
        }
        return $direccion;
    }

    /**
     * Get nombre con tipo
     */
    public function getNombreCompletoAttribute()
    {
        $nombre = $this->oficin_nombre;
        if ($this->tipoOficina) {
            $nombre = $this->tipoOficina->tofici_descripcion . ' - ' . $nombre;
        }
        return $nombre;
    }

    // ==================== SCOPES ====================

    /**
     * Scope para oficinas activas
     */
    public function scopeActivas($query)
    {
        return $query->where('oficin_ctractual', 1);
    }

    /**
     * Scope para oficinas por institución
     */
    public function scopePorInstitucion($query, $institucionId)
    {
        return $query->where('oficin_instit_codigo', $institucionId);
    }

    /**
     * Scope para oficinas por tipo (ÚTIL PARA EL TipoOficinaController)
     */
    public function scopePorTipo($query, $tipoId)
    {
        return $query->where('oficin_tofici_codigo', $tipoId);
    }

    /**
     * Scope para oficinas por parroquia
     */
    public function scopePorParroquia($query, $parroquiaId)
    {
        return $query->where('oficin_parroq_codigo', $parroquiaId);
    }

    /**
     * Scope para buscar oficinas
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where(function($q) use ($termino) {
            $q->where('oficin_nombre', 'ILIKE', "%{$termino}%")
              ->orWhere('oficin_direccion', 'ILIKE', "%{$termino}%")
              ->orWhere('oficin_diremail', 'ILIKE', "%{$termino}%")
              ->orWhere('oficin_telefono', 'ILIKE', "%{$termino}%")
              ->orWhere('oficin_rucoficina', 'ILIKE', "%{$termino}%");
        });
    }

    // ==================== MÉTODOS DE ESTADO ====================

    /**
     * Verificar si la oficina está activa
     */
    public function estaActiva()
    {
        return $this->oficin_ctractual == 1;
    }

    /**
     * Verificar si la oficina está cerrada
     */
    public function estaCerrada()
    {
        return $this->oficin_fechacierre && $this->oficin_fechacierre <= now();
    }

    /**
     * Contar usuarios asignados
     */
    public function cantidadUsuarios()
    {
        return $this->usuarios()->count();
    }

    /**
     * Obtener el tipo de oficina como texto
     */
    public function getTipoOficinaTexto()
    {
        return $this->tipoOficina?->tofici_descripcion ?? 'Sin tipo definido';
    }

    /**
     * Verificar si es matriz (puedes definir un código específico en tipos)
     */
    public function esMatriz()
    {
        return $this->tipoOficina?->tofici_codigo == 1; // Ajustar según tu configuración
    }

    /**
     * Verificar si es sucursal
     */
    public function esSucursal()
    {
        return $this->tipoOficina?->tofici_codigo == 2; // Ajustar según tu configuración
    }

    /**
     * Verificar si es agencia
     */
    public function esAgencia()
    {
        return $this->tipoOficina?->tofici_codigo == 3; // Ajustar según tu configuración
    }

    /**
     * Obtener información básica de la oficina (COMPATIBLE CON APIs)
     */
    public function getInfoBasica()
    {
        return [
            'oficin_codigo' => $this->oficin_codigo,
            'oficin_nombre' => $this->oficin_nombre,
            'nombre_completo' => $this->nombre_completo,
            'oficin_direccion' => $this->oficin_direccion,
            'direccion_completa' => $this->direccion_completa,
            'oficin_telefono' => $this->oficin_telefono,
            'oficin_diremail' => $this->oficin_diremail,
            'activa' => $this->estaActiva(),
            'cantidad_usuarios' => $this->cantidadUsuarios(),
            'institucion' => $this->institucion?->instit_nombre,
            'tipo_oficina' => $this->getTipoOficinaTexto(),
            'tipo_oficina_codigo' => $this->oficin_tofici_codigo,
            'parroquia' => $this->parroquia?->parroq_nombre
        ];
    }

    // ==================== EVENTOS DEL MODELO ====================

    /**
     * Boot method for model events
     */
    protected static function boot()
    {
        parent::boot();

        // Evento al crear una oficina
        static::creating(function ($oficina) {
            $oficina->oficin_fechaapertura = $oficina->oficin_fechaapertura ?? now();
            $oficina->oficin_ctractual = $oficina->oficin_ctractual ?? 1;
        });
    }
}