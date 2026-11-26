<?php

namespace App\Models\Teaching;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SyllabusChapter extends Model
{
    use SoftDeletes;

    protected $fillable = ['academic_year', 'class_name', 'subject_id', 'name', 'periods', 'position'];

    protected $casts = ['periods' => 'integer', 'position' => 'integer', 'subject_id' => 'integer'];

    public function topics()
    {
        return $this->hasMany(SyllabusTopic::class, 'chapter_id')->orderBy('position')->orderBy('id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function auditLabel(): string
    {
        return "Syllabus {$this->class_name} {$this->academic_year}: {$this->name}";
    }
}
