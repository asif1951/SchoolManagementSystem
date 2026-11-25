<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    levels:   { type: Array,  default: () => [] },
    level:    { type: String, default: '' },
    sections: { type: Array,  default: () => [] },
    subjects: { type: Array,  default: () => [] },
    years:    { type: Array,  default: () => [] },
    flash:    { type: Object, default: () => ({}) },
});

const newLevel = ref('');
const chosen     = reactive({});                    // section key -> ticked subject ids
const panelOpen  = reactive({});                    // section key -> is the "add subjects" panel open
const asOptional = reactive({});                    // section key -> add them as optional subjects
const applyYear = ref(props.years[0] ?? String(new Date().getFullYear()));
const replace   = ref(false);

const currentLevel = computed(() => props.level);

const goLevel = (name) => {
    if (!name) return;
    router.get(route('curriculum.index'), { class_name: name }, { preserveScroll: true });
};

const key = (section) => section.group_id ?? 'all';

const togglePanel = (section) => {
    const k = key(section);
    panelOpen[k] = !panelOpen[k];
    chosen[k] = chosen[k] ?? [];
};

const selectAll = (section) => { chosen[key(section)] = optionsFor(section).map((s) => s.id); };
const clearAll  = (section) => { chosen[key(section)] = []; };
const countChosen = (section) => (chosen[key(section)] ?? []).length;

// Add every ticked subject in ONE request.
const addMany = (section) => {
    const k = key(section);
    if (!countChosen(section)) return;

    router.post(route('curriculum.store-many'), {
        class_name: currentLevel.value,
        study_group_id: section.group_id,
        subject_ids: chosen[k],
        is_optional: !!asOptional[k],
    }, {
        preserveScroll: true,
        onSuccess: () => { chosen[k] = []; panelOpen[k] = false; asOptional[k] = false; },
    });
};

const toggleOptional = (item) => {
    router.put(route('curriculum.update', item.id), { is_optional: !item.is_optional }, { preserveScroll: true });
};

const remove = (item) => {
    router.delete(route('curriculum.destroy', item.id), { preserveScroll: true });
};

const applyTemplate = () => {
    const msg = replace.value
        ? `Replace the subjects of every ${currentLevel.value} class in ${applyYear.value} with this template?`
        : `Fill ${currentLevel.value} classes of ${applyYear.value} that have no subjects yet?`;

    if (!confirm(msg)) return;

    router.post(route('curriculum.apply'), { class_name: currentLevel.value, year: applyYear.value, replace: replace.value }, { preserveScroll: true });
};

const optionsFor = (section) => {
    const used = new Set(section.items.map((i) => i.subject_id));
    return props.subjects.filter((s) => !used.has(s.id));
};
</script>

<template>
    <Head title="Curriculum" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Curriculum templates</h1>
                <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                    Decide once which subjects a class level has: the common subjects, the extra subjects of each study group, and which ones are optional.
                    Then apply the template to that level's classes — you can still change any single class afterwards.
                </p>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <!-- level picker -->
            <div class="mb-6 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Class level</span>
                    <select :value="level" @change="goLevel($event.target.value)"
                            class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                        <option v-for="l in levels" :key="l" :value="l">{{ l }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Or a new level</span>
                    <input v-model="newLevel" type="text" placeholder="e.g. Class 10" @keyup.enter="goLevel(newLevel)"
                           class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                </label>
                <button type="button" class="rounded-xl border border-indigo-200 px-4 py-2.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50 dark:border-indigo-800 dark:hover:bg-indigo-950/30" @click="goLevel(newLevel)">Open</button>
            </div>

            <p v-if="!level" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">
                Create a class first, or type a class level above to start its template.
            </p>

            <div v-else class="space-y-5">
                <section v-for="section in sections" :key="key(section)"
                         class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
                    <h2 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ section.title }}</h2>

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="item in section.items" :key="item.id" class="flex items-center justify-between gap-3 py-2.5">
                            <span class="font-medium text-slate-800 dark:text-white">{{ item.name }}</span>
                            <div class="flex items-center gap-3">
                                <button type="button" @click="toggleOptional(item)"
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="item.is_optional ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">
                                    {{ item.is_optional ? 'Optional' : 'Compulsory' }}
                                </button>
                                <button type="button" class="text-xs font-semibold text-red-600 hover:underline" @click="remove(item)">Remove</button>
                            </div>
                        </li>
                        <li v-if="!section.items.length" class="py-3 text-sm text-slate-400">No subjects yet.</li>
                    </ul>

                    <div class="mt-3">
                        <button type="button" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400" @click="togglePanel(section)">
                            {{ panelOpen[key(section)] ? 'Close' : '+ Add subjects…' }}
                        </button>

                        <div v-if="panelOpen[key(section)]" class="mt-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <div v-if="!optionsFor(section).length" class="text-sm text-slate-400">Every subject is already in this list.</div>

                            <template v-else>
                                <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-semibold">
                                    <button type="button" class="text-indigo-600 hover:underline dark:text-indigo-400" @click="selectAll(section)">Select all</button>
                                    <button type="button" class="text-slate-500 hover:underline" @click="clearAll(section)">Clear</button>
                                    <label class="ml-auto flex items-center gap-2 font-medium text-slate-600 dark:text-slate-300">
                                        <input v-model="asOptional[key(section)]" type="checkbox" class="h-4 w-4 rounded border-slate-300" /> Add as optional
                                    </label>
                                </div>

                                <div class="grid max-h-64 gap-1 overflow-auto sm:grid-cols-2 lg:grid-cols-3">
                                    <label v-for="s in optionsFor(section)" :key="s.id"
                                           class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                                        <input v-model="chosen[key(section)]" type="checkbox" :value="s.id" class="h-4 w-4 rounded border-slate-300" />
                                        {{ s.name }}
                                    </label>
                                </div>

                                <div class="mt-3 flex justify-end">
                                    <button type="button" :disabled="!countChosen(section)" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40" @click="addMany(section)">
                                        Add selected ({{ countChosen(section) }})
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>

                <!-- apply -->
                <section class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/20 sm:p-5">
                    <h2 class="mb-1 text-sm font-bold text-indigo-900 dark:text-indigo-200">Apply this template to {{ level }} classes</h2>
                    <p class="mb-3 text-xs text-indigo-800/80 dark:text-indigo-300/80">
                        A class with a study group gets the common subjects plus its group's extras. A mixed class gets everything, each extra limited to its group.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <select v-model="applyYear" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input v-model="replace" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                            Replace existing subjects
                        </label>
                        <button type="button" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500" @click="applyTemplate">Apply template</button>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
