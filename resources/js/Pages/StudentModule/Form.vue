<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import DuplicatePanel from '@/Components/StudentModule/DuplicatePanel.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    mode: { type: String, default: 'create' },            // create | edit
    profile: { type: Object, default: null },
    classes: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    duplicate: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    preselect: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()
const editing = computed(() => props.mode === 'edit')
const p = props.profile || {}

const form = useForm({
    name_en: p.name_en || '', name_bn: p.name_bn || '', brn: p.brn || '', dob: p.dob || '', gender: p.gender || '',
    religion: p.religion || '', blood_group: p.blood_group || '',
    father_name: p.father_name || '', mother_name: p.mother_name || '',
    guardian_name: p.guardian_name || '', guardian_mobile: p.guardian_mobile || '', guardian_relation: p.guardian_relation || '',
    present_address: p.present_address || '', permanent_address: p.permanent_address || '',
    previous_school: p.previous_school || '', admission_date: p.admission_date || new Date().toISOString().slice(0, 10),
    class_id: props.preselect.class_id ? String(props.preselect.class_id) : '', roll_number: '',
    override_reason: '', change_reason: '',
})

// protected fields: someone without approval rights can only request a change, and must say why
const PROTECTED = ['name_en', 'name_bn', 'dob', 'father_name', 'mother_name', 'gender', 'brn']
const changedProtected = computed(() => editing.value
    ? PROTECTED.filter((k) => (form[k] || '') !== (k === 'brn' ? '' : (p[k] || '')))
    : [])
const needsReason = computed(() => editing.value && !props.can.approve_change && changedProtected.value.length > 0)

const submit = () => {
    if (editing.value) {
        form.put(route('sm.students.update', p.id), { preserveScroll: true })
    } else {
        form.post(route('sm.students.store'), { preserveScroll: true })
    }
}

const changeSession = (e) => {
    form.class_id = ''
    router.get(route('sm.students.create'), { session: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })
}

const input = 'h-9 w-full rounded-md border bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 dark:bg-slate-900 dark:text-slate-100'
const ok = 'border-slate-300 focus:border-blue-600 focus:ring-blue-600/20 dark:border-slate-700'
const bad = 'border-red-400 focus:border-red-500 focus:ring-red-500/20'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
const cls = (field) => [input, form.errors[field] ? bad : ok]
</script>

<template>
    <Head :title="editing ? t('edit') : t('add_student')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <form class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:py-7" novalidate @submit.prevent="submit">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    {{ t('module') }} /
                    <Link :href="route('sm.students.index')" class="hover:underline">{{ t('entry') }}</Link> /
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ editing ? t('edit') : t('add_student') }}</span>
                </nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ editing ? p.name_en : t('add_student') }}</h1>
                        <p v-if="editing" class="mt-0.5 font-mono text-xs text-slate-500">{{ p.code }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <!-- blocked: a student who already exists -->
                <DuplicatePanel
                    :duplicate="duplicate"
                    :message="form.errors.duplicate || ''"
                    :can-override="can.override_duplicate"
                    v-model:reason="form.override_reason"
                    :processing="form.processing"
                    @override="submit"
                />

                <div v-if="form.errors.protected" class="mb-5 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300" role="alert">{{ form.errors.protected }}</div>

                <!-- enrollment (new students) -->
                <section v-if="!editing" class="mb-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('enrollment') }}</h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label :class="label" for="session">{{ t('session') }}</label>
                            <select id="session" :value="session" :class="[input, ok]" @change="changeSession">
                                <option v-for="s in sessions" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="label" for="class_id">{{ t('class_section') }} *</label>
                            <select id="class_id" v-model="form.class_id" :class="cls('class_id')" required>
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
                    </div>
                </section>

                <section class="mb-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('personal') }}</h2>
                    <p v-if="editing && !can.approve_change" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('protected_note') }}</p>

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label :class="label" for="name_en">{{ t('name_en') }} *</label>
                            <input id="name_en" v-model="form.name_en" type="text" maxlength="255" required :class="cls('name_en')" />
                            <p v-if="form.errors.name_en" class="mt-1 text-xs text-red-600">{{ form.errors.name_en }}</p>
                        </div>
                        <div>
                            <label :class="label" for="name_bn">{{ t('name_bn') }}</label>
                            <input id="name_bn" v-model="form.name_bn" type="text" maxlength="255" :class="cls('name_bn')" lang="bn" />
                        </div>

                        <div class="sm:col-span-2">
                            <label :class="label" for="brn">{{ t('brn') }}</label>
                            <input id="brn" v-model="form.brn" type="text" inputmode="numeric" maxlength="40" autocomplete="off"
                                   :placeholder="editing && p.brn_masked ? p.brn_masked : ''" :class="cls('brn')" />
                            <p v-if="form.errors.brn" class="mt-1 text-xs text-red-600">{{ form.errors.brn }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('brn_hint') }}</p>
                        </div>

                        <div>
                            <label :class="label" for="dob">{{ t('dob') }}</label>
                            <input id="dob" v-model="form.dob" type="date" :max="new Date().toISOString().slice(0, 10)" :class="cls('dob')" />
                            <p v-if="form.errors.dob" class="mt-1 text-xs text-red-600">{{ form.errors.dob }}</p>
                        </div>
                        <div>
                            <label :class="label" for="gender">{{ t('gender') }}</label>
                            <select id="gender" v-model="form.gender" :class="cls('gender')">
                                <option value="">{{ t('select') }}</option>
                                <option value="Male">{{ t('male') }}</option>
                                <option value="Female">{{ t('female') }}</option>
                                <option value="Other">{{ t('other') }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="label" for="religion">{{ t('religion') }}</label>
                            <select id="religion" v-model="form.religion" :class="cls('religion')">
                                <option value="">{{ t('select') }}</option>
                                <option>Islam</option><option>Hinduism</option><option>Buddhism</option><option>Christianity</option><option>Other</option>
                            </select>
                        </div>
                        <div>
                            <label :class="label" for="blood_group">{{ t('blood_group') }}</label>
                            <select id="blood_group" v-model="form.blood_group" :class="cls('blood_group')">
                                <option value="">{{ t('select') }}</option>
                                <option v-for="b in ['A+','A-','B+','B-','AB+','AB-','O+','O-']" :key="b">{{ b }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="label" for="father_name">{{ t('father_name') }}</label>
                            <input id="father_name" v-model="form.father_name" type="text" maxlength="255" :class="cls('father_name')" />
                        </div>
                        <div>
                            <label :class="label" for="mother_name">{{ t('mother_name') }}</label>
                            <input id="mother_name" v-model="form.mother_name" type="text" maxlength="255" :class="cls('mother_name')" />
                        </div>
                    </div>
                </section>

                <section class="mb-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('guardian') }}</h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label :class="label" for="guardian_name">{{ t('guardian_name') }}</label>
                            <input id="guardian_name" v-model="form.guardian_name" type="text" maxlength="255" :class="cls('guardian_name')" />
                        </div>
                        <div>
                            <label :class="label" for="guardian_mobile">{{ t('mobile') }}</label>
                            <input id="guardian_mobile" v-model="form.guardian_mobile" type="tel" inputmode="tel" maxlength="30" placeholder="01XXXXXXXXX" :class="cls('guardian_mobile')" />
                            <p v-if="form.errors.guardian_mobile" class="mt-1 text-xs text-red-600">{{ form.errors.guardian_mobile }}</p>
                        </div>
                        <div>
                            <label :class="label" for="guardian_relation">{{ t('guardian_relation') }}</label>
                            <input id="guardian_relation" v-model="form.guardian_relation" type="text" maxlength="50" :class="cls('guardian_relation')" />
                        </div>
                    </div>
                </section>

                <section class="mb-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('address') }}</h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label :class="label" for="present_address">{{ t('present_address') }}</label>
                            <textarea id="present_address" v-model="form.present_address" rows="2" maxlength="1000" :class="[input, ok, 'h-auto py-2']"></textarea>
                        </div>
                        <div>
                            <label :class="label" for="permanent_address">{{ t('permanent_address') }}</label>
                            <textarea id="permanent_address" v-model="form.permanent_address" rows="2" maxlength="1000" :class="[input, ok, 'h-auto py-2']"></textarea>
                        </div>
                        <div>
                            <label :class="label" for="previous_school">{{ t('previous_school') }}</label>
                            <input id="previous_school" v-model="form.previous_school" type="text" maxlength="255" :class="cls('previous_school')" />
                        </div>
                        <div v-if="!editing">
                            <label :class="label" for="admission_date">{{ t('admission_date') }}</label>
                            <input id="admission_date" v-model="form.admission_date" type="date" :class="cls('admission_date')" />
                        </div>
                    </div>
                </section>

                <!-- a requester must explain a change to protected fields -->
                <section v-if="needsReason" class="mb-5 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                    <label class="mb-1 block text-sm font-semibold text-blue-900 dark:text-blue-200" for="change_reason">{{ t('change_reason') }} *</label>
                    <textarea id="change_reason" v-model="form.change_reason" rows="2" maxlength="500" required
                              :class="['w-full rounded-md border bg-white px-3 py-2 text-sm dark:bg-slate-900 dark:text-slate-100', form.errors.change_reason ? 'border-red-400' : 'border-blue-300 dark:border-blue-800']"></textarea>
                    <p v-if="form.errors.change_reason" class="mt-1 text-xs text-red-600">{{ form.errors.change_reason }}</p>
                </section>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Link :href="editing ? route('sm.students.show', p.id) : route('sm.students.index')" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ t('cancel') }}</Link>
                    <button type="submit" :disabled="form.processing" class="inline-flex h-9 items-center rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ needsReason ? t('request') : t('save') }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
