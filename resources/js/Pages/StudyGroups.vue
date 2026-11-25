<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    groups: { type: Array,  default: () => [] },
    flash:  { type: Object, default: () => ({}) },
});

const showForm = ref(false);
const editing  = ref(null);

const form = useForm({ name: '', code: '', description: '', sort_order: null, is_active: true });

const openAdd = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    showForm.value = true;
};

const openEdit = (g) => {
    editing.value = g;
    form.clearErrors();
    form.name = g.name;
    form.code = g.code ?? '';
    form.description = g.description ?? '';
    form.sort_order = g.sort_order;
    form.is_active = g.is_active;
    showForm.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    editing.value ? form.put(route('study-groups.update', editing.value.id), options) : form.post(route('study-groups.store'), options);
};

const toggleActive = (g) => {
    router.put(route('study-groups.update', g.id), {
        name: g.name, code: g.code, description: g.description, sort_order: g.sort_order, is_active: !g.is_active,
    }, { preserveScroll: true });
};

const remove = (g) => {
    if (confirm(`Delete the group "${g.name}"?`)) {
        router.delete(route('study-groups.destroy', g.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Study Groups" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Study Groups</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                        Groups such as Science, Commerce and Arts — add whatever your school uses. A class can belong to one group, or stay mixed and let each student pick.
                    </p>
                </div>
                <button type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500" @click="openAdd">+ Add group</button>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">{{ flash.error }}</div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="g in groups" :key="g.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900" :class="g.is_active ? '' : 'opacity-60'">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800 dark:text-white">{{ g.name }}</h2>
                            <p class="font-mono text-xs text-slate-400">{{ g.code || '—' }}</p>
                        </div>
                        <button type="button" @click="toggleActive(g)" class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="g.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'">
                            {{ g.is_active ? 'Active' : 'Inactive' }}
                        </button>
                    </div>
                    <p v-if="g.description" class="mt-2 text-sm text-slate-500">{{ g.description }}</p>
                    <p class="mt-3 text-xs text-slate-500">{{ g.classes_count }} class(es) · {{ g.students_count }} student(s)</p>
                    <div class="mt-3 flex gap-4">
                        <button type="button" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" @click="openEdit(g)">Edit</button>
                        <button type="button" class="text-xs font-semibold text-red-600 hover:underline" @click="remove(g)">Delete</button>
                    </div>
                </article>

                <p v-if="!groups.length" class="col-span-full rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">
                    No groups yet. Add Science, Commerce, Arts — or any group your school has.
                </p>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showForm = false">
            <form class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 sm:p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-bold text-slate-800 dark:text-white">{{ editing ? 'Edit group' : 'Add group' }}</h2>
                <div class="space-y-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Name *</span>
                        <input v-model="form.name" type="text" maxlength="100" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        <span v-if="form.errors.name" class="mt-1 block text-xs text-red-500">{{ form.errors.name }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Code</span>
                        <input v-model="form.code" type="text" maxlength="30" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        <span v-if="form.errors.code" class="mt-1 block text-xs text-red-500">{{ form.errors.code }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</span>
                        <input v-model="form.description" type="text" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                        <span class="text-sm text-slate-700 dark:text-slate-200">Active</span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300" @click="showForm = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50">Save</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
