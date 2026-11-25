<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import DuplicatePanel from '@/Components/StudentModule/DuplicatePanel.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    classes: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    duplicate: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    preselect: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()
const nameField = ref(null)

const form = useForm({
    name_en: '', gender: '', guardian_mobile: '', brn: '', dob: '',
    class_id: props.preselect.class_id ? String(props.preselect.class_id) : '', roll_number: '',
    override_reason: '', another: false,
})

const send = (another) => {
    form.another = another

    form.post(route('sm.students.quick.store'), {
        preserveScroll: true,
        onSuccess: async () => {
            // "Save & add another": the form stays, the child's details are cleared, the class is kept
            if (another) {
                form.reset('name_en', 'gender', 'guardian_mobile', 'brn', 'dob', 'roll_number', 'override_reason')
                await nextTick()
                nameField.value?.focus()
            }
        },
    })
}

const changeSession = (e) => {
    form.class_id = ''
    router.get(route('sm.students.quick'), { session: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })
}

const input = 'h-10 w-full rounded-md border bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 dark:bg-slate-900 dark:text-slate-100'
const ok = 'border-slate-300 focus:border-blue-600 focus:ring-blue-600/20 dark:border-slate-700'
const bad = 'border-red-400 focus:border-red-500 focus:ring-red-500/20'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
const cls = (field) => [input, form.errors[field] ? bad : ok]
</script>

<template>
    <Head :title="t('quick_entry')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <form class="mx-auto max-w-2xl px-4 py-5 sm:px-6 lg:py-7" novalidate @submit.prevent="send(false)">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    {{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('quick_entry') }}</span>
                </nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('quick_entry') }}</h1>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('quick_note') }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>

                <DuplicatePanel
                    :duplicate="duplicate"
                    :message="form.errors.duplicate || ''"
                    :can-override="can.override_duplicate"
                    v-model:reason="form.override_reason"
                    :processing="form.processing"
                    @override="send(false)"
                />

                <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label :class="label" for="name_en">{{ t('name_en') }} *</label>
                            <input id="name_en" ref="nameField" v-model="form.name_en" type="text" maxlength="255" required autofocus :class="cls('name_en')" />
                            <p v-if="form.errors.name_en" class="mt-1 text-xs text-red-600">{{ form.errors.name_en }}</p>
                        </div>

                        <div>
                            <label :class="label" for="gender">{{ t('gender') }} *</label>
                            <select id="gender" v-model="form.gender" required :class="cls('gender')">
                                <option value="">{{ t('select') }}</option>
                                <option value="Male">{{ t('male') }}</option>
                                <option value="Female">{{ t('female') }}</option>
                                <option value="Other">{{ t('other') }}</option>
                            </select>
                            <p v-if="form.errors.gender" class="mt-1 text-xs text-red-600">{{ form.errors.gender }}</p>
                        </div>
                        <div>
                            <label :class="label" for="guardian_mobile">{{ t('mobile') }} *</label>
                            <input id="guardian_mobile" v-model="form.guardian_mobile" type="tel" inputmode="tel" maxlength="30" required placeholder="01XXXXXXXXX" :class="cls('guardian_mobile')" />
                            <p v-if="form.errors.guardian_mobile" class="mt-1 text-xs text-red-600">{{ form.errors.guardian_mobile }}</p>
                        </div>

                        <div>
                            <label :class="label" for="session">{{ t('session') }}</label>
                            <select id="session" :value="session" :class="[input, ok]" @change="changeSession">
                                <option v-for="s in sessions" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="label" for="class_id">{{ t('class_section') }} *</label>
                            <select id="class_id" v-model="form.class_id" required :class="cls('class_id')">
                                <option value="">{{ t('select') }}</option>
                                <option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                            </select>
                            <p v-if="form.errors.class_id" class="mt-1 text-xs text-red-600">{{ form.errors.class_id }}</p>
                        </div>

                        <div>
                            <label :class="label" for="roll_number">{{ t('roll') }}</label>
                            <input id="roll_number" v-model="form.roll_number" type="text" inputmode="numeric" maxlength="50" :class="cls('roll_number')" />
                            <p v-if="form.errors.roll_number" class="mt-1 text-xs text-red-600">{{ form.errors.roll_number }}</p>
                        </div>
                        <div>
                            <label :class="label" for="dob">{{ t('dob') }}</label>
                            <input id="dob" v-model="form.dob" type="date" :max="new Date().toISOString().slice(0, 10)" :class="cls('dob')" />
                        </div>

                        <div class="sm:col-span-2">
                            <label :class="label" for="brn">{{ t('brn') }}</label>
                            <input id="brn" v-model="form.brn" type="text" inputmode="numeric" maxlength="40" autocomplete="off" :class="cls('brn')" />
                            <p v-if="form.errors.brn" class="mt-1 text-xs text-red-600">{{ form.errors.brn }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('brn_hint') }}</p>
                        </div>
                    </div>
                </section>

                <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                    <Link :href="route('sm.students.index')" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ t('cancel') }}</Link>
                    <button type="button" :disabled="form.processing" class="inline-flex h-9 items-center rounded-md border border-blue-700 bg-white px-4 text-sm font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50 dark:bg-slate-900 dark:text-blue-400" @click="send(true)">{{ t('save_add_another') }}</button>
                    <button type="submit" :disabled="form.processing" class="inline-flex h-9 items-center rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">{{ t('save') }}</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
