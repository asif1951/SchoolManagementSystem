<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';

const props = defineProps({
    exam:       { type: Object, required: true },
    classes:    { type: Array,  default: () => [] },
    classId:    { type: Number, default: null },
    subjects:   { type: Array,  default: () => [] },
    types:      { type: Array,  default: () => [] },
    otherExams: { type: Array,  default: () => [] },
    flash:      { type: Object, default: () => ({}) },
});

const locked = props.exam.status === 'published';

// editable copy: subject_id -> [{component_type_id, full_marks, pass_marks}]
const edit = reactive({});
const load = () => {
    Object.keys(edit).forEach((k) => delete edit[k]);
    props.subjects.forEach((s) => { edit[s.subject_id] = s.papers.map((p) => ({ ...p })); });
};
watch(() => props.subjects, load, { immediate: true });

const pick = (id) => router.get(route('exams.setup', props.exam.id), { class_id: id }, { preserveScroll: true });

const addPart = (subjectId) => {
    const used = new Set(edit[subjectId].map((p) => p.component_type_id));
    const free = props.types.find((t) => !used.has(t.id));
    if (!free) return;
    edit[subjectId].push({ component_type_id: free.id, full_marks: 100, pass_marks: 33 });
};
const removePart = (subjectId, i) => edit[subjectId].splice(i, 1);
const total = (subjectId) => edit[subjectId].reduce((sum, p) => sum + (Number(p.full_marks) || 0), 0);

const saveSubject = (subjectId) => {
    router.post(route('exams.setup.subject', props.exam.id), {
        class_id: props.classId, subject_id: subjectId, papers: edit[subjectId],
    }, { preserveScroll: true });
};

// ---------------- bulk tools
const fill = reactive({ open: false, replace: false, parts: [{ component_type_id: props.types[1]?.id ?? props.types[0]?.id, full_marks: 70, pass_marks: 23 }, { component_type_id: props.types[2]?.id ?? props.types[0]?.id, full_marks: 30, pass_marks: 10 }] });
const runFill = () => router.post(route('exams.setup.fill', props.exam.id), { class_id: props.classId, replace: fill.replace, papers: fill.parts }, { preserveScroll: true, onSuccess: () => { fill.open = false; } });

const targets = ref([]);
const copyOpen = ref(false);
const runApply = () => router.post(route('exams.setup.apply', props.exam.id), { class_id: props.classId, target_ids: targets.value }, { preserveScroll: true, onSuccess: () => { copyOpen.value = false; targets.value = []; } });

const sourceExam = ref('');
const runCopyExam = () => {
    if (sourceExam.value && confirm('Copy every paper of that exam into this one?')) {
        router.post(route('exams.setup.copy', props.exam.id), { source_exam_id: sourceExam.value }, { preserveScroll: true });
    }
};

const input = 'rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white';
</script>

<template>
    <Head :title="`${exam.name} — setup`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <Link :href="route('exams.index', { year: exam.academic_year })" class="text-sm font-semibold text-indigo-600 hover:underline">← All exams</Link>
                    <h1 class="mt-1 text-2xl font-bold text-slate-800 dark:text-white">{{ exam.name }} <span class="text-base font-medium text-slate-400">· {{ exam.academic_year }}</span></h1>
                    <p class="text-sm text-slate-500">For each class, choose which parts each subject has (CQ, MCQ, written, practical…) and their marks.</p>
                </div>

                <div v-if="!locked && otherExams.length" class="flex items-center gap-2">
                    <select v-model="sourceExam" :class="input"><option value="">Copy setup from…</option><option v-for="o in otherExams" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <button type="button" :disabled="!sourceExam" class="rounded-lg bg-slate-800 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-40" @click="runCopyExam">Copy</button>
                </div>
            </div>

            <div v-if="locked" class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">This exam is published, so its setup is locked.</div>
            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <div class="grid gap-6 lg:grid-cols-[260px_1fr]">

                <!-- classes -->
                <nav class="space-y-2">
                    <button v-for="c in classes" :key="c.id" type="button" @click="pick(c.id)"
                            class="w-full rounded-xl border p-3 text-left transition"
                            :class="classId === c.id ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/30' : 'border-slate-200 bg-white hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900'">
                        <div class="font-semibold text-slate-800 dark:text-white">{{ c.label }}</div>
                        <div class="mt-0.5 flex items-center gap-2 text-xs">
                            <span :class="c.subjects_set_up === c.subjects_total && c.subjects_total ? 'text-emerald-600' : 'text-slate-500'">{{ c.subjects_set_up }}/{{ c.subjects_total }} subjects set up</span>
                        </div>
                    </button>
                    <p v-if="!classes.length" class="text-sm text-slate-400">No classes exist for {{ exam.academic_year }}.</p>
                </nav>

                <!-- subjects of the chosen class -->
                <div>
                    <p v-if="!classId" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">Choose a class on the left.</p>

                    <div v-else class="space-y-4">
                        <div v-if="!locked" class="flex flex-wrap gap-2">
                            <button type="button" class="rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300" @click="fill.open = !fill.open">Same parts for every subject…</button>
                            <button type="button" class="rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300" @click="copyOpen = !copyOpen">Copy this class to other classes…</button>
                        </div>

                        <div v-if="fill.open" class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <p class="mb-2 text-sm font-semibold text-indigo-900 dark:text-indigo-200">Give every subject of this class these parts:</p>
                            <div v-for="(p, i) in fill.parts" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
                                <select v-model="p.component_type_id" :class="input"><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                                <label class="text-xs text-slate-500">Full <input v-model.number="p.full_marks" type="number" min="1" :class="input + ' w-20'" /></label>
                                <label class="text-xs text-slate-500">Pass <input v-model.number="p.pass_marks" type="number" min="0" :class="input + ' w-20'" /></label>
                                <button type="button" class="text-red-500" @click="fill.parts.splice(i, 1)">✕</button>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" class="text-sm font-semibold text-indigo-600" @click="fill.parts.push({ component_type_id: types[0]?.id, full_marks: 100, pass_marks: 33 })">+ part</button>
                                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input v-model="fill.replace" type="checkbox" class="h-4 w-4" /> Replace subjects that are already set up</label>
                                <button type="button" :disabled="!fill.parts.length" class="ml-auto rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white disabled:opacity-40" @click="runFill">Apply</button>
                            </div>
                        </div>

                        <div v-if="copyOpen" class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <p class="mb-2 text-sm font-semibold text-indigo-900 dark:text-indigo-200">Copy this class's setup to (only subjects they also offer are copied):</p>
                            <div class="mb-3 grid gap-1 sm:grid-cols-2">
                                <label v-for="c in classes.filter((x) => x.id !== classId)" :key="c.id" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <input v-model="targets" type="checkbox" :value="c.id" class="h-4 w-4" /> {{ c.label }}
                                </label>
                            </div>
                            <button type="button" :disabled="!targets.length" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white disabled:opacity-40" @click="runApply">Copy to {{ targets.length }} class(es)</button>
                        </div>

                        <article v-for="s in subjects" :key="s.subject_id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-bold text-slate-800 dark:text-white">{{ s.name }}</h3>
                                    <span v-if="s.is_optional" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Optional</span>
                                </div>
                                <span class="text-sm font-semibold" :class="edit[s.subject_id]?.length ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400'">
                                    {{ edit[s.subject_id]?.length ? `Total ${total(s.subject_id)} marks` : 'Not examined' }}
                                </span>
                            </div>

                            <div v-for="(p, i) in edit[s.subject_id]" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
                                <select v-model="p.component_type_id" :disabled="locked" :class="input">
                                    <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                                <label class="text-xs text-slate-500">Full marks <input v-model.number="p.full_marks" :disabled="locked" type="number" min="1" step="0.5" :class="input + ' w-24'" /></label>
                                <label class="text-xs text-slate-500">Pass marks <input v-model.number="p.pass_marks" :disabled="locked" type="number" min="0" step="0.5" :class="input + ' w-24'" /></label>
                                <button v-if="!locked" type="button" class="text-red-500" @click="removePart(s.subject_id, i)">✕</button>
                            </div>

                            <div v-if="!locked" class="mt-2 flex items-center gap-4">
                                <button type="button" class="text-sm font-semibold text-indigo-600 hover:underline" @click="addPart(s.subject_id)">+ Add part</button>
                                <button type="button" class="ml-auto rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500" @click="saveSubject(s.subject_id)">Save {{ s.name }}</button>
                            </div>
                        </article>

                        <p v-if="!subjects.length" class="text-sm text-slate-400">This class has no subjects yet — add them under Curriculum or Classes.</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
