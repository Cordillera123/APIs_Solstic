<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $table = 'tbl_perm';
    protected $primaryKey = 'perm_id';
    public $timestamps = false;
    
    protected $fillable = ['per_id', 'men_id', 'sub_id', 'opc_id'];
    
    public function perfil()
    {
        return $this->belongsTo(Perfil::class, 'per_id', 'per_id');
    }
    
    public function menu()
    {
        return $this->belongsTo(Menu::class, 'men_id', 'men_id');
    }
    
    public function submenu()
    {
        return $this->belongsTo(Submenu::class, 'sub_id', 'sub_id');
    }
    
    public function opcion()
    {
        return $this->belongsTo(Opcion::class, 'opc_id', 'opc_id');
    }
}