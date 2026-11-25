<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    roles:     { type: Array,  default: () => [] },
    catalogue: { type: Array,  default: () => [] },
    flash:     { type: Object, default: () => ({}) },
});

// ------------------------------------------------------------
// Selected role + editable copy
// ------------------------------------------------------------
const selectedId = ref(props.roles[0]?.id ?? null);
const creating   = ref(false);
const saving     = ref(false);

const selected = computed(() => props.roles.find((r) => r.id === selectedId.value) ?? null);

// level per ability: 'none' | 'own' | 'all'
const form = reactive({ name: '', description: '', levels: {} });

const loadForm = () => {
    form.levels = {};
    const granted = creating.value ? [] : (selected.value?.abilities ?? []);

    for (const group of props.catalogue) {
        for (const a of group.abilities) {
            form.levels[a.key] = granted.includes('*') || granted.includes(a.key)
                ? 'all'
                : granted.includes(a.key + ':own') ? 'own' : 'none';
        }
    }

    form.name        = creating.value ? '' : (selected.value?.name ?? '');
    form.description = creating.value ? '' : (selected.value?.description ?? '');
};

watch([selectedId, creating, () => props.roles], loadForm, { immediate: true });

const isAdminRole = computed(() => !creating.value && selected.value?.is_admin);

const abilityList = () => Object.entries(form.levels)
    .filter(([, level]) => level !== 'none')
    .map(([key, level]) => (level === 'own' ? key + ':own' : key));

const grantedCount = computed(() => Object.values(form.levels).filter((l) => l !== 'none').length);

// ------------------------------------------------------------
// Actions
// ------------------------------------------------------------
const startCreate = () => { creating.value = true; };
const pick = (id) => { creating.value = false; selectedId.value = id; };

const save = () => {
    saving.value = true;
    const payload = { name: form.name, description: form.description, abilities: abilityList() };
    const done = { preserveScroll: true, onFinish: () => { saving.value = false; } };

    if (creating.value) {
        router.post(route('roles.store'), payload, {
            ...done,
            onSuccess: () => {
                creating.value = false;
                selectedId.value = props.roles[props.roles.length - 1]?.id ?? selectedId.value;
            },
        });
    } else {
        router.put(route('roles.update', selected.value.id), payload, done);
    }
};

const remove = () => {
    if (!selected.value || !confirm(`Delete the role "${selected.value.name}"?`)) return;
    router.delete(route('roles.destroy', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { selectedId.value = props.roles[0]?.id ?? null; },
    });
};

const levelClass = (level) => ({
    none: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    own:  'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    all:  'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
}[level]);
</script>

<template>
    <Head title="Roles & Permissions" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">

            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Roles &amp; Permissions</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Decide what each role can do. "Own classes" limits a role to the classes the user is class teacher of.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                    @click="startCreate"
                >
                    + New role
                </button>
            </div>

            <div v-if="flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">
                {{ flash.success }}
            </div>
            <div v-if="flash?.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300">
                {{ flash.error }}
            </div>

            <div class="grid gap-6 lg:grid-cols-[280px_1fr]">

                <!-- ============ role list ============ -->
                <div class="space-y-2">
                    <button
                        v-for="role in roles"
                        :key="role.id"
                        type="button"
                        class="w-full rounded-xl border p-3 text-left transition"
                        :class="!creating && selectedId === role.id
                            ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/30'
                            : 'border-slate-200 bg-white hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900'"
                        @click="pick(role.id)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-slate-800 dark:text-white">{{ role.name }}</span>
                            <span v-if="role.is_system" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">built-in</span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ role.users_count }} user(s)</p>
                    </button>
                </div>

                <!-- ============ editor ============ -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6">

                    <div v-if="creating" class="mb-4 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-300">
                        Creating a new role
                    </div>

                    <div v-if="isAdminRole" class="mb-4 rounded-lg bg-purple-50 px-3 py-2 text-sm text-purple-800 dark:bg-purple-950/30 dark:text-purple-300">
                        The administrator role always has every permission and cannot be reduced.
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Role name</span>
                            <input v-model="form.name" type="text" maxlength="100"
                                   class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</span>
                            <input v-model="form.description" type="text" maxlength="255"
                                   class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                        </label>
                    </div>

                    <div class="mt-6 space-y-6">
                        <section v-for="group in catalogue" :key="group.group">
                            <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ group.group }}</h2>

                            <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                                <div v-for="ability in group.abilities" :key="ability.key"
                                     class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 dark:text-white">{{ ability.label }}</p>
                                        <p class="font-mono text-[11px] text-slate-400">{{ ability.key }}</p>
                                    </div>

                                    <!-- scoped: none / own classes / whole school -->
                                    <select v-if="ability.scoped" v-model="form.levels[ability.key]" :disabled="isAdminRole"
                                            class="rounded-lg border-0 px-3 py-1.5 text-sm font-semibold"
                                            :class="levelClass(form.levels[ability.key])">
                                        <option value="none">No</option>
                                        <option value="own">Own classes only</option>
                                        <option value="all">Whole school</option>
                                    </select>

                                    <!-- not scoped: on / off -->
                                    <select v-else v-model="form.levels[ability.key]" :disabled="isAdminRole"
                                            class="rounded-lg border-0 px-3 py-1.5 text-sm font-semibold"
                                            :class="levelClass(form.levels[ability.key])">
                                        <option value="none">No</option>
                                        <option value="all">Yes</option>
                                    </select>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="text-sm text-slate-500">{{ grantedCount }} permission(s) granted</p>

                        <div class="flex gap-2">
                            <button v-if="!creating && selected && !selected.is_system" type="button"
                                    class="rounded-xl border border-red-200 px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:hover:bg-red-950/30"
                                    @click="remove">
                                Delete role
                            </button>
                            <button type="button" :disabled="saving || !form.name.trim()"
                                    class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50"
                                    @click="save">
                                {{ saving ? 'Saving…' : (creating ? 'Create role' : 'Save changes') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
