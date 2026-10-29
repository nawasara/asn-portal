<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Versioned privacy policy and terms of service.
|
| Every change is a NEW row, never an edit of the published one: the
| documents promise that changes are announced 14 days before they apply,
| and what a user agreed to on a given date must still be readable later.
| A published row is therefore immutable (enforced in the model).
|
| `effective_at` in the future = announced but not yet in force; the public
| endpoint returns it as `upcoming` next to the current version.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_asn_portal_legal_documents', function (Blueprint $table) {
            $table->id();

            // privacy | terms
            $table->string('type', 20);
            $table->string('version', 20);
            $table->string('title', 160);

            // Markdown, same reason as help items.
            $table->longText('body');
            $table->text('change_summary')->nullable();

            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Explicit short names: the generated ones exceed MySQL's 64
            // character identifier limit with this table name, and the
            // migration fails half-way (the table is already created).
            $table->unique(['type', 'version'], 'asn_portal_legal_type_version_unique');
            $table->index(['type', 'published_at', 'effective_at'], 'asn_portal_legal_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_asn_portal_legal_documents');
    }
};
