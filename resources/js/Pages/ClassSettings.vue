<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    shifts:  { type: Array,  default: () => [] },
    mediums: { type: Array,  default: () => [] },
    flash:   { type: Object, default: () => ({}) },
});

// one layout drives both lists
const sections = computed(() => [
    { kind: 'shifts', title: 'Shifts', one: 'shift', hint: 'When the class runs — for example Morning and Day.', rows: props.shifts },
    { kind: 'mediums', title: 'Mediums', one: 'medium', hint: 'The language of instruction — for example Bangla and English.', rows: props.mediums },
]);

const editing = ref(null);          // { kind, row|null }
const form = useForm({ name: '', sort_order: null, is_active: true });

const openAdd = (s) => {
    editing.value = { kind: s.kind, one: s.one, row: null };
    form.reset();
    form.clearErrors();
    form.is_active = true;
};

const openEdit = (s, row) => {
    editing.value = { kind: s.kind, one: s.one, row };
    form.clearErrors();
    form.name = row.name;
    form.sort_order = row.sort_order;
    form.is_active = row.is_active;
};

const submit = () => {
    const { kind, row } = editing.value;
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };

    row
        ? form.put(route('class-settings.update', { kind, id: row.id }), options)
        : form.post(route('class-settings.store', { kind }), options);
};

const toggleActive = (s, row) => {
    router.put(route('class-settings.update', { kind: s.kind, id: row.id }), {
        name: row.name, sort_order: row.sort_order, is_active: !row.is_active,
    }, { preserveScroll: true });
};

const remove = (s, row) => {
    if (confirm(`Delete the ${s.one} "${row.name}"?`)) {
        router.delete(route('class-settings.destroy', { kind: s.kind, id: row.id }), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Shifts & Mediums" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Shifts &amp; Mediums</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    Every class belongs to one shift and one medium, so "Class 6 A · Morning · Bangla" and
                    "Class 6 A · Day · English" are separate classes with their own students, marks and results.
                    Add whatever your school runs. A shift or medium that classes use can be switched off but not deleted.
                </p>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-for="s in sections" :key="s.kind" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-lg font-bold text-slate-800 dark:text-white">{{ s.title }}</h2>
                            <p class="text-xs text-slate-500">{{ s.hint }}</p>
                        </div>
                        <button type="button" class="shrink-0 rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-semibold text-white shadow hover:bg-indigo-500" @click="openAdd(s)">+ Add {{ s.one }}</button>
                    </div>

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="row in s.rows" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 py-3" :class="row.is_active ? '' : 'opacity-60'">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-800 dark:text-white">{{ row.name }}</p>
                                <p class="text-xs text-slate-500">{{ row.classes_count }} class(es)</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <button type="button" @click="toggleActive(s, row)" class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="row.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'">
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </button>
                                <button type="button" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" @click="openEdit(s, row)">Edit</button>
                                <button type="button" class="text-xs font-semibold text-red-600 hover:underline" @click="remove(s, row)">Delete</button>
                            </div>
                        </li>
                        <li v-if="!s.rows.length" class="py-6 text-center text-sm text-slate-500">None yet.</li>
                    </ul>
                </section>
            </div>
        </div>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="editing = null">
            <form class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 sm:p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-bold text-slate-800 dark:text-white">{{ editing.row ? 'Edit' : 'Add' }} {{ editing.one }}</h2>
                <div class="space-y-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Name *</span>
                        <input v-model="form.name" type="text" maxlength="60" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        <span v-if="form.errors.name" class="mt-1 block text-xs text-red-500">{{ form.errors.name }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Order in lists</span>
                        <input v-model="form.sort_order" type="number" min="0" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                        <span class="text-sm text-slate-700 dark:text-slate-200">Active</span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300" @click="editing = null">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50">Save</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
