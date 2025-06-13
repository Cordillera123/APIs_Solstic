<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptionButton extends Model
{
    protected $table = 'tbl_opc_bot';
    protected $primaryKey = 'opc_bot_id';
    public $timestamps = false;
    
    protected $fillable = [
        'opc_id',
        'bot_id',
        'opc_bot_requerido',
        'opc_bot_orden',
        'opc_bot_activo'
    ];

    protected $casts = [
        'opc_bot_requerido' => 'boolean',
        'opc_bot_activo' => 'boolean',
        'opc_bot_orden' => 'integer'
    ];

    // Relación con opción
    public function opcion()
    {
        return $this->belongsTo(Opcion::class, 'opc_id', 'opc_id');
    }

    // Relación con botón
    public function boton()
    {
        return $this->belongsTo(Button::class, 'bot_id', 'bot_id');
    }

    // Scope para activos
    public function scopeActive($query)
    {
        return $query->where('opc_bot_activo', true);
    }

    // Scope para ordenar
    public function scopeOrdered($query)
    {
        return $query->orderBy('opc_bot_orden');
    }
}
