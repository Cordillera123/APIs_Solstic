<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoOficina extends Model
{
    protected $table = 'gaf_tofici';
    protected $primaryKey = 'tofici_codigo';
    public $timestamps = false; // La tabla no tiene timestamps
    public $incrementing = false; // TEMPORAL: porque no tiene auto-increment

    protected $fillable = [
        'tofici_codigo',
        'tofici_descripcion',
        'tofici_abreviatura'
    ];

    // Relación con oficinas
    public function oficinas()
    {
        return $this->hasMany(Oficina::class, 'oficin_tofici_codigo', 'tofici_codigo');
    }
}