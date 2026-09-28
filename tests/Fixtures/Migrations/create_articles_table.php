<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->uuid('code')->primary();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('slug', 120);
            $table->boolean('active')->default(true);
            $table->string('summary')->nullable()->default(null);
            $table->unique(['tenant_id', 'slug'], 'articles_tenant_slug_unique');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
