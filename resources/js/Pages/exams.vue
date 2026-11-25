<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    year:        { type: String, required: true },
    years:       { type: Array,  default: () => [] },
    exams:       { type: Array,  default: () => [] },
    weightTotal: { type: Number, default: 0 },
    locked:      { type: Boolean, default: false },
    policy:      { type: Object, default: () => ({}) },
    scales:      { type: Array,  default: () => [] },
    types:       { type: Array,  default: () => [] },
    flash:       { type: Object, default: () => ({}) },
});

const tab = ref('exams');
const tabs = [['exams', 'Exams'], ['rules', 'Result rules'], ['scales', 'Grading scales'], ['types', 'Paper parts']];

const changeYear = (e) => router.get(route('exams.index'), { year: e.target.value }, { preserveScroll: true });

// ------------------------------------------------------------ exams
const showExam = ref(false);
const editingExam = ref(null);
const examForm = useForm({ academic_year: props.year, name: '', weight: 0, starts_on: '', ends_on: '' });

const weightOk = computed(() => Math.abs(props.weightTotal - 100) < 0.001);

const openExam = (e = null) => {
    editingExam.value = e;
    examForm.clearErrors();
    examForm.academic_year = props.year;
    examForm.name = e?.name ?? '';
    examForm.weight = e?.weight ?? Math.max(0, 100 - props.weightTotal);
    examForm.starts_on = e?.starts_on ?? '';
    examForm.ends_on = e?.ends_on ?? '';
    showExam.value = true;
};

const saveExam = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showExam.value = false; } };
    editingExam.value ? examForm.put(route('exams.update', editingExam.value.id), opts) : examForm.post(route('exams.store'), opts);
};

const nextSteps = { draft: [['open', 'Open for marking']], open: [['closed', 'Close']], closed: [['open', 'Reopen'], ['published', 'Publish']], published: [['closed', 'Unpublish']] };
const setStatus = (exam, status) => router.post(route('exams.status', exam.id), { status }, { preserveScroll: true });
const removeExam = (exam) => { if (confirm(`Delete "${exam.name}" and its setup?`)) router.delete(route('exams.destroy', exam.id), { preserveScroll: true }); };

const badge = (s) => ({
    draft: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    open: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    closed: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    published: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
}[s]);

// ------------------------------------------------------------ result rules
const rules = reactive({ ...props.policy });
const saveRules = () => router.post(route('exams.policy'), { ...rules, academic_year: props.year }, { preserveScroll: true });

// ------------------------------------------------------------ grading scales
const showScale = ref(false);
const editingScale = ref(null);
const scaleForm = useForm({ name: '', rows: [] });

const openScale = (s = null) => {
    editingScale.value = s;
    scaleForm.clearErrors();
    scaleForm.name = s?.name ?? '';
    scaleForm.rows = s ? s.rows.map((r) => ({ ...r })) : [
        { min_percent: 80, grade: 'A+', grade_point: 5, is_fail: false },
        { min_percent: 0, grade: 'F', grade_point: 0, is_fail: true },
    ];
    showScale.value = true;
};
const addRow = () => scaleForm.rows.push({ min_percent: 0, grade: '', grade_point: 0, is_fail: false });
const saveScale = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showScale.value = false; } };
    editingScale.value ? scaleForm.put(route('grading-scales.update', editingScale.value.id), opts) : scaleForm.post(route('grading-scales.store'), opts);
};
const makeDefault = (s) => router.post(route('grading-scales.default', s.id), {}, { preserveScroll: true });
const removeScale = (s) => { if (confirm(`Delete the scale "${s.name}"?`)) router.delete(route('grading-scales.destroy', s.id), { preserveScroll: true }); };

// ------------------------------------------------------------ paper parts
const typeForm = useForm({ name: '', code: '' });
const addType = () => typeForm.post(route('exam-types.store'), { preserveScroll: true, onSuccess: () => typeForm.reset() });
const toggleType = (t) => router.put(route('exam-types.update', t.id), { name: t.name, code: t.code, sort_order: t.sort_order, is_active: !t.is_active }, { preserveScroll: true });
const removeType = (t) => { if (confirm(`Delete "${t.name}"?`)) router.delete(route('exam-types.destroy', t.id), { preserveScroll: true }); };

const input = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white';
const label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
</script>

<template>
    <Head title="Exams" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Exam management</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                        Set up the year's exams and weights, decide how the final result is calculated, and define each subject's parts and marks.
                    </p>
                </div>
                <select :value="year" @change="changeYear" :class="input + ' !w-auto font-semibold'">
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-700">
                <button v-for="[key, text] in tabs" :key="key" type="button" @click="tab = key"
                        class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                        :class="tab === key ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 hover:text-slate-700'">{{ text }}</button>
            </div>

            <!-- ================= EXAMS ================= -->
            <section v-if="tab === 'exams'">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="rounded-xl px-4 py-2 text-sm font-semibold" :class="weightOk ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300'">
                        Weights add up to {{ weightTotal }}% <span v-if="!weightOk">— must be exactly 100% before publishing</span>
                    </div>
                    <button type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500" @click="openExam()">+ New exam</button>
                </div>

                <div class="space-y-3">
                    <article v-for="e in exams" :key="e.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h2 class="text-lg font-bold text-slate-800 dark:text-white">{{ e.name }}</h2>
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="badge(e.status)">{{ e.status }}</span>
                                </div>
                                <p class="text-sm text-slate-500">Counts for <b>{{ e.weight }}%</b> of the final result · {{ e.papers_count }} paper(s) set up
                                    <span v-if="e.starts_on"> · {{ e.starts_on }} → {{ e.ends_on || '…' }}</span></p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Link :href="route('exams.setup', e.id)" class="rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-300">Subjects &amp; marks</Link>
                                <button v-for="[to, text] in nextSteps[e.status]" :key="to" type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200" @click="setStatus(e, to)">{{ text }}</button>
                                <button v-if="e.status !== 'published'" type="button" class="text-xs font-semibold text-indigo-600 hover:underline" @click="openExam(e)">Edit</button>
                                <button v-if="e.status === 'draft'" type="button" class="text-xs font-semibold text-red-600 hover:underline" @click="removeExam(e)">Delete</button>
                            </div>
                        </div>
                    </article>
                    <p v-if="!exams.length" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">No exams for {{ year }} yet. Create the first one.</p>
                </div>
            </section>

            <!-- ================= RULES ================= -->
            <section v-if="tab === 'rules'" class="max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div v-if="locked" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">Results of {{ year }} are published, so these rules are locked.</div>

                <div class="space-y-5">
                    <label class="block"><span :class="label">How the final result is built</span>
                        <select v-model="rules.calculation_mode" :disabled="locked" :class="input">
                            <option value="per_subject">Per subject — combine each subject across the exams, then grade it</option>
                            <option value="overall_percentage">Overall percentage — one weighted percentage for the whole student</option>
                        </select></label>

                    <label class="block"><span :class="label">Where pass marks are checked</span>
                        <select v-model="rules.pass_rule_scope" :disabled="locked" :class="input">
                            <option value="final_only">Only on the combined final result</option>
                            <option value="each_exam">In every exam</option>
                        </select></label>

                    <label class="flex items-start gap-3">
                        <input v-model="rules.require_component_pass" :disabled="locked" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300" />
                        <span class="text-sm text-slate-700 dark:text-slate-200">A student must also reach the pass marks of <b>each part</b> (CQ, MCQ, practical…) of a subject.</span>
                    </label>

                    <label class="block"><span :class="label">Grading scale</span>
                        <select v-model="rules.grading_scale_id" :disabled="locked" :class="input">
                            <option v-for="s in scales" :key="s.id" :value="s.id">{{ s.name }}{{ s.is_default ? ' (default)' : '' }}</option>
                        </select></label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span :class="label">Optional ("4th") subject</span>
                            <select v-model="rules.optional_rule" :disabled="locked" :class="input">
                                <option value="above_threshold">Only points above a threshold count</option>
                                <option value="none">No special rule</option>
                            </select></label>
                        <label v-if="rules.optional_rule === 'above_threshold'" class="block"><span :class="label">Threshold (grade point)</span>
                            <input v-model.number="rules.optional_threshold" :disabled="locked" type="number" step="0.01" min="0" max="5" :class="input" /></label>
                    </div>

                    <label class="block"><span :class="label">Marks for attendance</span>
                        <input v-model.number="rules.attendance_marks" :disabled="locked" type="number" step="0.5" min="0" max="100" :class="input" />
                        <span class="mt-1 block text-xs text-slate-500">Marks a student with perfect attendance earns. They are added to the tabulation total in proportion to attendance. 0 means attendance is shown but earns no marks.</span></label>

                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60">An absent student always gets 0 — and so a fail — in the subject they missed.</p>

                    <div class="flex justify-end"><button type="button" :disabled="locked" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-40" @click="saveRules">Save rules for {{ year }}</button></div>
                </div>
            </section>

            <!-- ================= SCALES ================= -->
            <section v-if="tab === 'scales'">
                <div class="mb-4 flex justify-end"><button type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500" @click="openScale()">+ New scale</button></div>
                <div class="grid gap-4 md:grid-cols-2">
                    <article v-for="s in scales" :key="s.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="mb-2 flex items-center justify-between">
                            <h2 class="font-bold text-slate-800 dark:text-white">{{ s.name }}</h2>
                            <span v-if="s.is_default" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">default</span>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="text-xs uppercase text-slate-400"><tr><th class="text-left">From %</th><th class="text-left">Grade</th><th class="text-left">Point</th></tr></thead>
                            <tbody><tr v-for="r in s.rows" :key="r.min_percent" :class="r.is_fail ? 'text-red-600' : 'text-slate-700 dark:text-slate-200'">
                                <td>{{ r.min_percent }}</td><td class="font-semibold">{{ r.grade }}</td><td>{{ r.grade_point.toFixed(2) }}</td></tr></tbody>
                        </table>
                        <div class="mt-3 flex gap-4 text-xs font-semibold">
                            <button type="button" class="text-indigo-600 hover:underline" @click="openScale(s)">Edit</button>
                            <button v-if="!s.is_default" type="button" class="text-slate-600 hover:underline" @click="makeDefault(s)">Make default</button>
                            <button v-if="!s.is_default" type="button" class="text-red-600 hover:underline" @click="removeScale(s)">Delete</button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- ================= TYPES ================= -->
            <section v-if="tab === 'types'" class="max-w-xl">
                <form class="mb-4 flex gap-2" @submit.prevent="addType">
                    <input v-model="typeForm.name" required placeholder="New part, e.g. Lab report" :class="input" />
                    <input v-model="typeForm.code" placeholder="Code" :class="input + ' !w-28'" />
                    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Add</button>
                </form>
                <p v-if="typeForm.errors.name" class="mb-2 text-xs text-red-500">{{ typeForm.errors.name }}</p>
                <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-700 dark:bg-slate-900">
                    <li v-for="t in types" :key="t.id" class="flex items-center justify-between px-4 py-3" :class="t.is_active ? '' : 'opacity-60'">
                        <span class="font-medium text-slate-800 dark:text-white">{{ t.name }} <span class="ml-1 font-mono text-xs text-slate-400">{{ t.code }}</span></span>
                        <span class="flex gap-4 text-xs font-semibold">
                            <button type="button" class="text-slate-600 hover:underline" @click="toggleType(t)">{{ t.is_active ? 'Deactivate' : 'Activate' }}</button>
                            <button type="button" class="text-red-600 hover:underline" @click="removeType(t)">Delete</button>
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- exam modal -->
        <div v-if="showExam" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showExam = false">
            <form class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900" @submit.prevent="saveExam">
                <h2 class="mb-4 text-lg font-bold text-slate-800 dark:text-white">{{ editingExam ? 'Edit exam' : 'New exam' }} — {{ year }}</h2>
                <div class="space-y-4">
                    <label class="block"><span :class="label">Name *</span><input v-model="examForm.name" required maxlength="100" placeholder="e.g. Half Yearly" :class="input" /><span v-if="examForm.errors.name" class="text-xs text-red-500">{{ examForm.errors.name }}</span></label>
                    <label class="block"><span :class="label">Counts for (% of final result) *</span><input v-model.number="examForm.weight" type="number" min="0" max="100" step="0.01" :class="input" /><span v-if="examForm.errors.weight" class="text-xs text-red-500">{{ examForm.errors.weight }}</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span :class="label">Starts</span><input v-model="examForm.starts_on" type="date" :class="input" /></label>
                        <label class="block"><span :class="label">Ends</span><input v-model="examForm.ends_on" type="date" :class="input" /></label>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 dark:border-slate-700" @click="showExam = false">Cancel</button>
                    <button type="submit" :disabled="examForm.processing" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">Save</button>
                </div>
            </form>
        </div>

        <!-- scale modal -->
        <div v-if="showScale" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showScale = false">
            <form class="max-h-[90vh] w-full max-w-lg overflow-auto rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900" @submit.prevent="saveScale">
                <h2 class="mb-4 text-lg font-bold text-slate-800 dark:text-white">{{ editingScale ? 'Edit scale' : 'New grading scale' }}</h2>
                <label class="mb-4 block"><span :class="label">Name *</span><input v-model="scaleForm.name" required :class="input" /><span v-if="scaleForm.errors.name" class="text-xs text-red-500">{{ scaleForm.errors.name }}</span></label>
                <div class="mb-1 grid grid-cols-[1fr_1fr_1fr_auto_auto] gap-2 text-xs font-semibold uppercase text-slate-400"><span>From %</span><span>Grade</span><span>Point</span><span>Fail?</span><span></span></div>
                <div v-for="(r, i) in scaleForm.rows" :key="i" class="mb-2 grid grid-cols-[1fr_1fr_1fr_auto_auto] items-center gap-2">
                    <input v-model.number="r.min_percent" type="number" step="0.01" min="0" max="100" :class="input" />
                    <input v-model="r.grade" required maxlength="10" :class="input" />
                    <input v-model.number="r.grade_point" type="number" step="0.01" min="0" :class="input" />
                    <input v-model="r.is_fail" type="checkbox" class="h-4 w-4" />
                    <button type="button" class="text-red-500" @click="scaleForm.rows.splice(i, 1)">✕</button>
                </div>
                <button type="button" class="text-sm font-semibold text-indigo-600" @click="addRow">+ Add grade</button>
                <p class="mt-2 text-xs text-slate-500">The lowest grade must start at 0% and at least one grade must be a failing grade.</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 dark:border-slate-700" @click="showScale = false">Cancel</button>
                    <button type="submit" :disabled="scaleForm.processing" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">Save</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
