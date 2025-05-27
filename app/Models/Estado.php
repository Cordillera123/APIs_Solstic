<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estado extends Model
{
    protected $table = 'tbl_est';
    protected $primaryKey = 'est_id';
    public $timestamps = false;
    
    protected $fillable = ['est_nom'];
    
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'est_id', 'est_id');
    }
}