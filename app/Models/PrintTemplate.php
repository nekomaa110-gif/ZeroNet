<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    protected $fillable = ['name', 'per_row', 'html', 'bawaan'];

    protected $casts = ['per_row' => 'integer'];

    public function getKunciAttribute(): string
    {
        return 't' . $this->id;
    }

    public static function pilihan(): array
    {
        $bawaan = array_map(fn ($t) => $t['label'], (array) config('voucher.print_templates'));

        return $bawaan + self::orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($nama, $id) => ["t{$id}" => $nama])->all();
    }
}
