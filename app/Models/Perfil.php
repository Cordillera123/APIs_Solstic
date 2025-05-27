<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Perfil extends Model
{
    protected $table = 'tbl_per';
    protected $primaryKey = 'per_id';
    public $timestamps = false;
    
    protected $fillable = ['per_nom'];
    
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'per_id', 'per_id');
    }
    
    public function permisos()
    {
        return $this->hasMany(Permiso::class, 'per_id', 'per_id');
    }
}