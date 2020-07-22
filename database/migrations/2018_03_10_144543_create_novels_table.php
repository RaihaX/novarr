<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNovelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('novels', function (Blueprint $table) {
            $table->increments('id');
            $table->string("name");
            $table->text('description')->nullable();
            $table->string("author")->nullable();
            $table->string('translator')->nullable();
            $table->string('translator_url')->nullable();
            $table->integer('current_chapters')->default(0);
            $table->integer('chapters_not_downloaded')->default(0);
            $table->integer('duplicate_chapters')->default(0);
            $table->integer('no_of_chapters')->default(1);
            $table->double('progress', 18, 2)->default(0);
            $table->integer('missing_chapters')->default(0);
            $table->boolean('status')->default(0);
            $table->string("alternative_url")->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('novels');
    }
}
