<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    year:     { type: String, required: true },
    years:    { type: Array,  default: () => [] },
    classes:  { type: Array,  default: () => [] },
    teachers: { type: Array,  default: () => [] },
    flash:    { type: Object, default: () => ({}) },
});

const search  = ref('');
const history = ref({ open: false, title: '', rows: [], loading: false });

const changeYear = (event) => {
    router.get(route('subject-teachers.index'), { year: event.target.value }, { preserveScroll: true });
};

// the whole list of a subject's teachers is saved at once, as soon as it changes
// what just happened, shown where you are looking (the page can be long, the banner at the top is out of sight)
const toast = ref(null);
let toastTimer = null;
const notify = (type, text) => {
    toast.value = { type, text };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 6000);
};

const save = (classId, subject, ids) => {
    router.post(route('subject-teachers.assign'), { class_id: classId, subject, user_ids: ids }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const f = page.props.flash || {};
            f.error ? notify('error', f.error) : notify('success', f.success || 'Saved.');
        },
        onError: (errors) => notify('error', Object.values(errors)[0] || 'The teachers could not be saved.'),
    });
};
const add = (c, s, event) => {
    const id = Number(event.target.value);
    event.target.value = '';
    if (id && !s.teacher_ids.includes(id)) save(c.id, s.name, [...s.teacher_ids, id]);
};
const remove = (c, s, id) => save(c.id, s.name, s.teacher_ids.filter((x) => x !== id));
const nameOf = (id) => props.teachers.find((t) => t.id === id)?.name ?? 'Teacher';
const free = (s) => props.teachers.filter((t) => !s.teacher_ids.includes(t.id));

const visibleClasses = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.classes;

    return props.classes.filter((c) =>
        `${c.class_name} ${c.section ?? ''}`.toLowerCase().includes(q)
        || c.subjects.some((s) => s.name.toLowerCase().includes(q)));
});

const unassignedCount = computed(() =>
    props.classes.reduce((sum, c) => sum + c.subjects.filter((s) => !s.teacher_ids.length).length, 0));

const label = (c) => `${c.class_label || c.class_name}${c.section_label || c.section ? ' — ' + (c.section_label || c.section) : ''}`;

const showHistory = async (c) => {
    history.value = { open: true, title: label(c), rows: [], loading: true };

    try {
        const res = await fetch(route('subject-teachers.history', c.id), { headers: { Accept: 'application/json' } });
        history.value.rows = await res.json();
    } finally {
        history.value.loading = false;
    }
};
</script>

<template>
    <Head title="Subject Teachers" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Subject Teachers</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                        Choose who teaches each subject in each class. A subject can have more than one teacher (for example Science with a Physics and a Chemistry teacher):
                        each of them enters marks for it, and the routine shares its periods between them. If a subject has no teacher, the class teacher enters its marks.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <input v-model="search" type="search" placeholder="Search class or subject…"
                           class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    <select :value="year" @change="changeYear"
                            class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <div v-if="unassignedCount" class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                {{ unassignedCount }} subject(s) in {{ year }} have no teacher yet — their class teachers will enter those marks.
            </div>

            <div class="grid gap-6 lg:grid-cols-[1fr_260px]">

                <!-- classes -->
                <div class="space-y-4">
                    <div v-if="!visibleClasses.length" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">
                        No classes found for {{ year }}.
                    </div>

                    <section v-for="c in visibleClasses" :key="c.id"
                             class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
                        <header class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-bold text-slate-800 dark:text-white">{{ label(c) }}</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Class teacher: {{ c.class_teacher || '— not assigned —' }}</p>
                            </div>
                            <button type="button" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" @click="showHistory(c)">
                                History
                            </button>
                        </header>

                        <div v-if="!c.subjects.length" class="text-sm text-slate-400">This class has no subjects yet.</div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div v-for="s in c.subjects" :key="s.name" class="rounded-lg border p-3"
                                 :class="s.teacher_ids.length ? 'border-slate-200 dark:border-slate-700' : 'border-amber-300 dark:border-amber-700'">
                                <p class="mb-2 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ s.name }}</p>

                                <ul v-if="s.teacher_ids.length" class="mb-2 flex flex-wrap gap-1.5">
                                    <li v-for="id in s.teacher_ids" :key="id" class="inline-flex items-center gap-1 rounded-md border border-brand-200 bg-brand-50 py-1 pl-2.5 pr-1 text-sm text-brand-900">
                                        {{ nameOf(id) }}
                                        <button type="button" class="rounded px-1.5 text-brand-700 hover:bg-brand-100" :aria-label="`Remove ${nameOf(id)} from ${s.name}`" @click="remove(c, s, id)">×</button>
                                    </li>
                                </ul>
                                <p v-else class="mb-2 text-sm text-amber-800">No teacher: the class teacher handles it</p>

                                <select class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" :aria-label="`Add a teacher to ${s.name}`" @change="add(c, s, $event)">
                                    <option value="">{{ s.teacher_ids.length ? '+ Add another teacher' : '+ Add a teacher' }}</option>
                                    <option v-for="t in free(s)" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- workload -->
                <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Teaching load ({{ year }})</h3>
                    <ul class="space-y-1.5">
                        <li v-for="t in teachers" :key="t.id" class="flex items-center justify-between text-sm">
                            <span class="truncate text-slate-700 dark:text-slate-200">{{ t.name }}</span>
                            <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold"
                                  :class="t.load ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'">
                                {{ t.load }}
                            </span>
                        </li>
                        <li v-if="!teachers.length" class="text-sm text-slate-400">No active teachers yet.</li>
                    </ul>
                </aside>
            </div>
        </div>

        <div v-if="toast" class="fixed bottom-4 right-4 z-50 max-w-sm rounded-md border border-l-4 border-slate-200 bg-white px-4 py-3 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900"
             :class="toast.type === 'error' ? 'border-l-red-600' : 'border-l-green-600'" :role="toast.type === 'error' ? 'alert' : 'status'">
            {{ toast.text }}
            <button type="button" class="ml-2 text-slate-400 hover:text-slate-700" aria-label="Dismiss" @click="toast = null">×</button>
        </div>

        <!-- history modal -->
        <div v-if="history.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="history.open = false">
            <div class="max-h-[80vh] w-full max-w-lg overflow-auto rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-bold text-slate-800 dark:text-white">History — {{ history.title }}</h3>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="history.open = false">✕</button>
                </div>

                <p v-if="history.loading" class="text-sm text-slate-500">Loading…</p>
                <p v-else-if="!history.rows.length" class="text-sm text-slate-500">No subject teacher has been assigned yet.</p>

                <table v-else class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="py-1">Subject</th><th>Teacher</th><th>From</th><th>To</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in history.rows" :key="i" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1.5 font-medium text-slate-800 dark:text-white">{{ r.subject }}</td>
                            <td class="text-slate-700 dark:text-slate-200">{{ r.teacher_name }}</td>
                            <td class="text-slate-500">{{ r.starts_on }}</td>
                            <td class="text-slate-500">{{ r.ends_on ?? 'current' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
