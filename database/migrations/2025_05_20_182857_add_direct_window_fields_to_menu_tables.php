<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddDirectWindowFieldsToMenuTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Verificar y añadir men_ventana_directa a tbl_men si no existe
        if (!$this->columnExists('tbl_men', 'men_ventana_directa')) {
            Schema::table('tbl_men', function (Blueprint $table) {
                $table->boolean('men_ventana_directa')->default(false);
            });
        }
        
        // Verificar y añadir men_componente a tbl_men si no existe
        if (!$this->columnExists('tbl_men', 'men_componente')) {
            Schema::table('tbl_men', function (Blueprint $table) {
                $table->string('men_componente', 100)->nullable();
            });
        }
        
        // Verificar y añadir sub_ventana_directa a tbl_sub si no existe
        if (!$this->columnExists('tbl_sub', 'sub_ventana_directa')) {
            Schema::table('tbl_sub', function (Blueprint $table) {
                $table->boolean('sub_ventana_directa')->default(false);
            });
        }
        
        // Verificar y añadir sub_componente a tbl_sub si no existe
        if (!$this->columnExists('tbl_sub', 'sub_componente')) {
            Schema::table('tbl_sub', function (Blueprint $table) {
                $table->string('sub_componente', 100)->nullable();
            });
        }
        
        // Verificar y añadir opc_componente a tbl_opc si no existe
        if (!$this->columnExists('tbl_opc', 'opc_componente')) {
            Schema::table('tbl_opc', function (Blueprint $table) {
                $table->string('opc_componente', 100)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // No hacemos nada en down() ya que solo queremos eliminar
        // las columnas si realmente intentamos añadirlas
    }
    
    /**
     * Verificar si una columna existe en una tabla
     */
    private function columnExists($table, $column)
    {
        return DB::connection()->getSchemaBuilder()->hasColumn($table, $column);
    }
}