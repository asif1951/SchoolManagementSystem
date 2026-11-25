<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subject module.
 *
 *  subjects        → the school's master list of subjects (admin-controlled)
 *  class_subjects  → which subjects each class (= one class row per year) offers
 *
 * Replaces the free-text JSON list `classes.subjects`, where "Math" and "Maths" were
 * two different subjects. Existing names are converted (case/whitespace variants are
 * merged into one spelling) and the JSON column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();           // utf8mb4_unicode_ci → case-insensitive unique
            $table->string('code', 30)->nullable()->unique();
            $table->string('short_name', 30)->nullable();
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_optional')->default(false);   // e.g. a "4th subject" (used by the exam rules later)
            $table->timestamps();

            $table->unique(['class_id', 'subject_id']);
            $table->index('subject_id');
        });

        // ---- convert the existing names --------------------------------------------------
        $canonical = [];   // lowercase key => the spelling we keep

        $remember = function ($name) use (&$canonical) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
            if ($name !== '' && ! isset($canonical[mb_strtolower($name)])) {
                $canonical[mb_strtolower($name)] = $name;
            }
        };

        $classRows = DB::table('classes')->get(['id', 'subjects']);

        foreach ($classRows as $class) {
            foreach ((json_decode((string) $class->subjects, true) ?: []) as $name) {
                $remember($name);
            }
        }
        foreach (DB::table('class_subject_teachers')->distinct()->pluck('subject') as $name) {
            $remember($name);
        }
        foreach (DB::table('results')->distinct()->pluck('subject') as $name) {
            $remember($name);
        }

        $ids = [];
        $order = 0;
        foreach ($canonical as $key => $name) {
            $ids[$key] = DB::table('subjects')->insertGetId([
                'name' => $name, 'sort_order' => ++$order, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ($classRows as $class) {
            $position = 0;
            foreach ((json_decode((string) $class->subjects, true) ?: []) as $name) {
                $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name)));
                if ($key === '' || ! isset($ids[$key])) {
                    continue;
                }
                DB::table('class_subjects')->insertOrIgnore([
                    'class_id' => $class->id, 'subject_id' => $ids[$key], 'sort_order' => $position++,
                    'is_optional' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Names stored as plain strings elsewhere must match the kept spelling.
        foreach ($canonical as $name) {
            DB::table('class_subject_teachers')->where('subject', $name)->where('subject', '!=', DB::raw('BINARY ' . DB::getPdo()->quote($name)))->update(['subject' => $name]);
            DB::table('results')->where('subject', $name)->where('subject', '!=', DB::raw('BINARY ' . DB::getPdo()->quote($name)))->update(['subject' => $name]);
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('subjects');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->json('subjects')->nullable()->after('status');
        });

        foreach (DB::table('classes')->pluck('id') as $classId) {
            $names = DB::table('class_subjects as cs')
                ->join('subjects as s', 's.id', '=', 'cs.subject_id')
                ->where('cs.class_id', $classId)
                ->orderBy('cs.sort_order')
                ->pluck('s.name')
                ->all();

            DB::table('classes')->where('id', $classId)->update(['subjects' => json_encode($names)]);
        }

        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
    }
};
