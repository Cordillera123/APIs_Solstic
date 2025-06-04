<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Config extends Model
{
    // Nombre de la tabla
    protected $table = 'tbl_config';

    // Clave primaria personalizada
    protected $primaryKey = 'conf_id';

    // Desactivar timestamps automáticos
    public $timestamps = false;

    // Campos asignables masivamente
    protected $fillable = [
        'conf_nom',
        'conf_detalle'
    ];

    // Castings de tipos
    protected $casts = [
        'conf_detalle' => 'string',
    ];

    // ==================== SCOPES ====================

    /**
     * Scope para buscar configuraciones por nombre
     */
    public function scopeBuscarPorNombre($query, $termino)
    {
        return $query->where('conf_nom', 'ILIKE', "%{$termino}%");
    }

    /**
     * Scope para ordenar por nombre
     */
    public function scopeOrdenadosPorNombre($query)
    {
        return $query->orderBy('conf_nom', 'asc');
    }

    // ==================== MÉTODOS DE UTILIDAD ====================

    /**
     * Obtener información básica de la configuración
     */
    public function getInfoBasica()
    {
        return [
            'conf_id' => $this->conf_id,
            'conf_nom' => $this->conf_nom,
            'conf_detalle' => $this->conf_detalle,
        ];
    }

    /**
     * Actualizar el detalle de la configuración
     */
    public function actualizarDetalle($nuevoDetalle)
    {
        $this->update([
            'conf_detalle' => $nuevoDetalle,
        ]);
    }
}
