<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Help content for the portal: FAQ entries and the "Tidak bisa masuk?"
| ways out on the guest page.
|
| `answer` is Markdown, never HTML. The old portal stored answers with raw
| <br> and <a> tags and rendered them unescaped; moving content behind an
| API is the moment to stop shipping markup that the frontend has to trust.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_asn_portal_help_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();

            // faq | sign_in_help
            $table->string('kind', 20)->default('faq');

            $table->string('question', 255);
            $table->text('answer');

            // Optional call to action, e.g. the SimasHebat password reset page.
            $table->string('action_label', 80)->nullable();
            $table->string('action_url', 500)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_asn_portal_help_items');
    }
};
