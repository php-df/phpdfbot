<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterTableOpportunities extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('position', 250)->nullable()->change();
            $table->string('salary', 250)->nullable()->change();
            $table->string('company', 250)->nullable()->change();
            $table->string('location', 250)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('position', 50)->nullable()->change();
            $table->string('salary', 50)->nullable()->change();
            $table->string('company', 50)->nullable()->change();
            $table->string('location', 50)->nullable()->change();
        });
    }
}
