<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNovelChaptersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('novel_chapters', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('novel_id')->default(0);
            $table->string('label')->nullable();
            $table->longtext('description')->nullable();
            $table->string('url')->nullable();
            $table->boolean('status')->default(0);
            $table->string('unique_id')->nullable();
            $table->timestamp('download_date')->nullable();
            $table->string("html_file")->nullable();
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
        Schema::dropIfExists('novel_chapters');
    }
}
