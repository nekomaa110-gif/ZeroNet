<?php

use App\Support\TemplateCetakBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_ROUTER = [
        'divisi-1' => 'mikhmon-standar',
        'divisi-3' => 'mikhmon-standar',
        'divisi-4' => 'mikhmon-kecil',
    ];

    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->unsignedTinyInteger('per_row')->default(0);
            $t->text('html');
            $t->string('bawaan', 24)->nullable()->unique();
            $t->timestamps();
        });

        Schema::table('routers', function (Blueprint $t) {
            $t->string('template_cetak', 20)->nullable()->after('last_sales_pull_at');
        });

        $id = [];
        foreach (TemplateCetakBawaan::semua() as $kunci => $tpl) {
            $id[$kunci] = DB::table('print_templates')->insertGetId($tpl + ['bawaan' => $kunci, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (self::DEFAULT_ROUTER as $slug => $kunci) {
            DB::table('routers')->where('slug', $slug)->whereNull('template_cetak')->update(['template_cetak' => 't' . $id[$kunci]]);
        }
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $t) {
            $t->dropColumn('template_cetak');
        });

        Schema::dropIfExists('print_templates');
    }
};
