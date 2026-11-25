<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    subjects: { type: Array,  default: () => [] },
    flash:    { type: Object, default: () => ({}) },
});

const search   = ref('');
const showForm = ref(false);
const editing  = ref(null);          // subject being edited, or null = adding

const form = useForm({
    name: '', code: '', short_name: '', description: '', sort_order: null, is_active: true,
});

// ---- add several subjects at once
const showBulk = ref(false);
const bulk = useForm({ names: '' });

const bulkCount = computed(() => bulk.names.split(/[\r\n,;]+/).map((n) => n.trim()).filter(Boolean).length);

const submitBulk = () => {
    bulk.post(route('subjects.store-many'), {
        preserveScroll: true,
        onSuccess: () => { showBulk.value = false; bulk.reset(); },
    });
};

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.subjects;
    return props.subjects.filter((s) => `${s.name} ${s.code ?? ''} ${s.short_name ?? ''}`.toLowerCase().includes(q));
});

const activeCount = computed(() => props.subjects.filter((s) => s.is_active).length);

const openAdd = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    showForm.value = true;
};

const openEdit = (s) => {
    editing.value = s;
    form.clearErrors();
    form.name = s.name;
    form.code = s.code ?? '';
    form.short_name = s.short_name ?? '';
    form.description = s.description ?? '';
    form.sort_order = s.sort_order;
    form.is_active = s.is_active;
    showForm.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };

    if (editing.value) {
        form.put(route('subjects.update', editing.value.id), options);
    } else {
        form.post(route('subjects.store'), options);
    }
};

const toggleActive = (s) => {
    router.put(route('subjects.update', s.id), {
        name: s.name, code: s.code, short_name: s.short_name, description: s.description,
        sort_order: s.sort_order, is_active: !s.is_active,
    }, { preserveScroll: true });
};

const remove = (s) => {
    if (!confirm(`Delete the subject "${s.name}"?`)) return;
    router.delete(route('subjects.destroy', s.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Subjects" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Subjects</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                        The school's master list of subjects. Classes and exams pick from this list, so a subject is spelled the same everywhere.
                        {{ activeCount }} of {{ subjects.length }} active.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <input v-model="search" type="search" placeholder="Search subjects…"
                           class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    <button type="button" class="rounded-xl border border-indigo-200 px-4 py-2.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/30" @click="showBulk = true">
                        Add several…
                    </button>
                    <button type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500" @click="openAdd">
                        + Add subject
                    </button>
                </div>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Subject</th>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Used in</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in filtered" :key="s.id" :class="s.is_active ? '' : 'opacity-60'">
                            <td class="px-4 py-3 text-slate-400">{{ s.sort_order }}</td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-800 dark:text-white">{{ s.name }}</div>
                                <div v-if="s.description" class="text-xs text-slate-500">{{ s.description }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ s.code || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ s.classes_count }} class(es)</td>
                            <td class="px-4 py-3">
                                <button type="button" @click="toggleActive(s)"
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="s.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'">
                                    {{ s.is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" class="mr-3 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" @click="openEdit(s)">Edit</button>
                                <button type="button" class="text-xs font-semibold text-red-600 hover:underline" @click="remove(s)">Delete</button>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="6" class="px-4 py-10 text-center text-slate-400">No subjects found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- add / edit -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showForm = false">
            <form class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 sm:p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-bold text-slate-800 dark:text-white">{{ editing ? 'Edit subject' : 'Add subject' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Name *</span>
                        <input v-model="form.name" type="text" maxlength="100" required
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        <span v-if="form.errors.name" class="mt-1 block text-xs text-red-500">{{ form.errors.name }}</span>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Code</span>
                        <input v-model="form.code" type="text" maxlength="30" placeholder="e.g. 101"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        <span v-if="form.errors.code" class="mt-1 block text-xs text-red-500">{{ form.errors.code }}</span>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Short name</span>
                        <input v-model="form.short_name" type="text" maxlength="30" placeholder="e.g. Math"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</span>
                        <input v-model="form.description" type="text" maxlength="255"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Order</span>
                        <input v-model.number="form.sort_order" type="number" min="0"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    </label>

                    <label class="flex items-end gap-2 pb-2.5">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                        <span class="text-sm text-slate-700 dark:text-slate-200">Active (can be added to classes)</span>
                    </label>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300" @click="showForm = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50">
                        {{ form.processing ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </form>
        </div>
        <!-- add several -->
        <div v-if="showBulk" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showBulk = false">
            <form class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 sm:p-6" @submit.prevent="submitBulk">
                <h2 class="mb-1 text-lg font-bold text-slate-800 dark:text-white">Add several subjects</h2>
                <p class="mb-3 text-sm text-slate-500">Type or paste one subject per line. Names that already exist are skipped.</p>
                <textarea v-model="bulk.names" rows="10" required placeholder="Physics&#10;Chemistry&#10;Biology&#10;Higher Mathematics"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"></textarea>
                <span v-if="bulk.errors.names" class="mt-1 block text-xs text-red-500">{{ bulk.errors.names }}</span>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <span class="text-sm text-slate-500">{{ bulkCount }} name(s)</span>
                    <div class="flex gap-2">
                        <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300" @click="showBulk = false">Cancel</button>
                        <button type="submit" :disabled="bulk.processing || !bulkCount" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50">Add {{ bulkCount }} subject(s)</button>
                    </div>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
