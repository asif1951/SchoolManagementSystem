<?php

namespace App\Models\Teaching;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SyllabusTopic extends Model
{
    use SoftDeletes;

    protected $fillable = ['chapter_id', 'name', 'position'];

    protected $casts = ['chapter_id' => 'integer', 'position' => 'integer'];

    public function chapter()
    {
        return $this->belongsTo(SyllabusChapter::class, 'chapter_id')->withTrashed();
    }
}
