<?php

// ==================== MODELO INSTITUCIÓN ====================

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institucion extends Model
{
    protected $table = 'gaf_instit';
    protected $primaryKey = 'instit_codigo';
    public $timestamps = false;

    protected $fillable = [
        'instit_codigo',
        'instit_nombre',
        'instit_segmen_codigo'
    ];

    protected $casts = [
        'instit_codigo' => 'integer',
        'instit_segmen_codigo' => 'integer'
    ];

    /**
     * Relación con segmento
     */
    public function segmento()
    {
        return $this->belongsTo(Segmento::class, 'instit_segmen_codigo', 'segmen_codigo');
    }

    /**
     * Relación con oficinas
     */
    public function oficinas()
    {
        return $this->hasMany(Oficina::class, 'oficin_instit_codigo', 'instit_codigo');
    }

    /**
     * Scope para instituciones activas
     */
    public function scopeActivas($query)
    {
        return $query; // Agregar condición si existe campo activo
    }

    /**
     * Contar oficinas de esta institución
     */
    public function cantidadOficinas()
    {
        return $this->oficinas()->count();
    }

    /**
     * Información básica
     */
    public function getInfoBasica()
    {
        return [
            'instit_codigo' => $this->instit_codigo,
            'instit_nombre' => $this->instit_nombre,
            'segmento' => $this->segmento?->segmen_descripcion,
            'cantidad_oficinas' => $this->cantidadOficinas()
        ];
    }
}

// ==================== MODELO SEGMENTO ====================

class Segmento extends Model
{
    protected $table = 'gaf_segmen';
    protected $primaryKey = 'segmen_codigo';
    public $timestamps = false;

    protected $fillable = [
        'segmen_codigo',
        'segmen_descripcion',
        'segmen_abreviatura'
    ];

    protected $casts = [
        'segmen_codigo' => 'integer'
    ];

    /**
     * Relación con instituciones
     */
    public function instituciones()
    {
        return $this->hasMany(Institucion::class, 'instit_segmen_codigo', 'segmen_codigo');
    }
}

// ==================== MODELO ESTADO REGISTRO ====================

class EstadoRegistro extends Model
{
    protected $table = 'gaf_eregis';
    protected $primaryKey = 'eregis_codigo';
    public $timestamps = false;

    protected $fillable = [
        'eregis_descripcion',
        'eregis_abreviatura'
    ];

    protected $casts = [
        'eregis_codigo' => 'integer'
    ];

    /**
     * Relación con oficinas
     */
    public function oficinas()
    {
        return $this->hasMany(Oficina::class, 'oficin_eregis_codigo', 'eregis_codigo');
    }

    /**
     * Contar oficinas con este estado
     */
    public function cantidadOficinas()
    {
        return $this->oficinas()->count();
    }
}

// ==================== MODELO PARROQUIA ====================

class Parroquia extends Model
{
    protected $table = 'gaf_parroq';
    protected $primaryKey = 'parroq_codigo';
    public $timestamps = false;

    protected $fillable = [
        'parroq_nombre',
        'parroq_canton_codigo',
        'parroq_abreviatura'
    ];

    protected $casts = [
        'parroq_codigo' => 'integer',
        'parroq_canton_codigo' => 'integer'
    ];

    /**
     * Relación con cantón
     */
    public function canton()
    {
        return $this->belongsTo(Canton::class, 'parroq_canton_codigo', 'canton_codigo');
    }

    /**
     * Relación con oficinas
     */
    public function oficinas()
    {
        return $this->hasMany(Oficina::class, 'oficin_parroq_codigo', 'parroq_codigo');
    }

    /**
     * Obtener dirección completa
     */
    public function getDireccionCompletaAttribute()
    {
        $direccion = $this->parroq_nombre;
        if ($this->canton) {
            $direccion .= ', ' . $this->canton->canton_nombre;
            if ($this->canton->provincia) {
                $direccion .= ', ' . $this->canton->provincia->provin_nombre;
            }
        }
        return $direccion;
    }

    /**
     * Scope por cantón
     */
    public function scopePorCanton($query, $cantonId)
    {
        return $query->where('parroq_canton_codigo', $cantonId);
    }
}

// ==================== MODELO CANTÓN ====================

class Canton extends Model
{
    protected $table = 'gaf_canton';
    protected $primaryKey = 'canton_codigo';
    public $timestamps = false;

    protected $fillable = [
        'canton_nombre',
        'canton_provin_codigo',
        'canton_abreviatura'
    ];

    protected $casts = [
        'canton_codigo' => 'integer',
        'canton_provin_codigo' => 'integer'
    ];

    /**
     * Relación con provincia
     */
    public function provincia()
    {
        return $this->belongsTo(Provincia::class, 'canton_provin_codigo', 'provin_codigo');
    }

    /**
     * Relación con parroquias
     */
    public function parroquias()
    {
        return $this->hasMany(Parroquia::class, 'parroq_canton_codigo', 'canton_codigo');
    }

    /**
     * Scope por provincia
     */
    public function scopePorProvincia($query, $provinciaId)
    {
        return $query->where('canton_provin_codigo', $provinciaId);
    }
}

// ==================== MODELO PROVINCIA ====================

class Provincia extends Model
{
    protected $table = 'gaf_provin';
    protected $primaryKey = 'provin_codigo';
    public $timestamps = false;

    protected $fillable = [
        'provin_nombre',
        'provin_abreviatura'
    ];

    protected $casts = [
        'provin_codigo' => 'integer'
    ];

    /**
     * Relación con cantones
     */
    public function cantones()
    {
        return $this->hasMany(Canton::class, 'canton_provin_codigo', 'provin_codigo');
    }

    /**
     * Obtener todas las parroquias de esta provincia
     */
    public function parroquias()
    {
        return $this->hasManyThrough(
            Parroquia::class,
            Canton::class,
            'canton_provin_codigo', // Foreign key en cantones
            'parroq_canton_codigo', // Foreign key en parroquias
            'provin_codigo',        // Local key en provincias
            'canton_codigo'         // Local key en cantones
        );
    }
}

// ==================== MODELO USUARIO (REFERENCIA) ====================

class Usuario extends Model
{
    protected $table = 'tbl_usu';
    protected $primaryKey = 'usu_id';
    public $timestamps = false;

    protected $fillable = [
        'usu_nom',
        'usu_nom2',
        'usu_ape',
        'usu_ape2',
        'usu_cor',
        'usu_ced',
        'usu_tel',
        'usu_dir',
        'per_id',
        'est_id',
        'oficin_codigo'
    ];

    protected $casts = [
        'usu_id' => 'integer',
        'per_id' => 'integer',
        'est_id' => 'integer',
        'oficin_codigo' => 'integer'
    ];

    /**
     * Relación con oficina
     */
    public function oficina()
    {
        return $this->belongsTo(Oficina::class, 'oficin_codigo', 'oficin_codigo');
    }

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
}

// ==================== CLASES ADICIONALES (SI LAS NECESITAS) ====================

class Perfil extends Model
{
    protected $table = 'tbl_per';
    protected $primaryKey = 'per_id';
    public $timestamps = false;

    protected $fillable = [
        'per_nom',
        'per_descripcion',
        'per_nivel',
        'per_activo'
    ];
}

class Estado extends Model
{
    protected $table = 'tbl_est';
    protected $primaryKey = 'est_id';
    public $timestamps = false;

    protected $fillable = [
        'est_nom',
        'est_des',
        'est_activo'
    ];
}