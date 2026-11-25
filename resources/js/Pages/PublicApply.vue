<script setup>
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { useT } from '@/composables/useT'

defineProps({
    session: { type: String, default: '' },
    classNames: { type: Array, default: () => [] },
    done: { type: Object, default: null },
})

const t = useT()
const form = useForm({
    class_name: '', name_en: '', name_bn: '', brn: '', dob: '', gender: '', religion: '',
    father_name: '', mother_name: '', guardian_name: '', guardian_mobile: '', guardian_relation: '',
    present_address: '', previous_school: '', website: '',
})
const submit = () => form.post(route('apply.store'), { preserveScroll: true, onSuccess: () => form.reset() })

const input = 'h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300'
const err = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <Head :title="t('apply_title')" />

    <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
            <header class="mb-5 flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">{{ t('apply_title') }}</h1>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ t('apply_intro') }} ({{ session }})</p>
                </div>
                <LanguageSwitch />
            </header>

            <section v-if="done" class="rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white p-5 dark:border-slate-700 dark:bg-slate-900" role="status">
                <h2 class="text-lg font-semibold">{{ t('apply_done_title') }}</h2>
                <p class="mt-1 text-sm">{{ t('apply_done_text').replace(':no', done.no) }}</p>
                <a :href="route('apply.show')" class="mt-3 inline-block text-sm font-medium text-blue-700 hover:underline dark:text-blue-400">{{ t('apply_another') }}</a>
            </section>

            <form v-else class="space-y-4 rounded-md border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" novalidate @submit.prevent="submit">
                <!-- honeypot: invisible to people, tempting to programs -->
                <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
                </div>

                <div><label :class="label" for="class_name">{{ t('apply_class') }} *</label>
                    <select id="class_name" v-model="form.class_name" :class="input" required><option value="">—</option><option v-for="c in classNames" :key="c" :value="c">{{ c }}</option></select>
                    <p v-if="form.errors.class_name" :class="err" role="alert">{{ form.errors.class_name }}</p></div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label :class="label" for="name_en">{{ t('name_en') }} *</label><input id="name_en" v-model="form.name_en" type="text" maxlength="255" :class="input" required /><p v-if="form.errors.name_en" :class="err" role="alert">{{ form.errors.name_en }}</p></div>
                    <div><label :class="label" for="name_bn">{{ t('name_bn') }}</label><input id="name_bn" v-model="form.name_bn" type="text" maxlength="255" :class="input" /></div>
                    <div><label :class="label" for="dob">{{ t('dob') }} *</label><input id="dob" v-model="form.dob" type="date" :max="new Date().toISOString().slice(0, 10)" :class="input" required /><p v-if="form.errors.dob" :class="err" role="alert">{{ form.errors.dob }}</p></div>
                    <div><label :class="label" for="gender">{{ t('gender') }} *</label>
                        <select id="gender" v-model="form.gender" :class="input" required><option value="">—</option><option>Male</option><option>Female</option><option>Other</option></select>
                        <p v-if="form.errors.gender" :class="err" role="alert">{{ form.errors.gender }}</p></div>
                    <div><label :class="label" for="brn">BRN</label><input id="brn" v-model="form.brn" type="text" inputmode="numeric" maxlength="40" :class="input" /><p v-if="form.errors.brn" :class="err" role="alert">{{ form.errors.brn }}</p></div>
                    <div><label :class="label" for="religion">{{ t('religion') }}</label><input id="religion" v-model="form.religion" type="text" maxlength="40" :class="input" /></div>
                    <div><label :class="label" for="father_name">{{ t('father_name') }} *</label><input id="father_name" v-model="form.father_name" type="text" maxlength="255" :class="input" required /><p v-if="form.errors.father_name" :class="err" role="alert">{{ form.errors.father_name }}</p></div>
                    <div><label :class="label" for="mother_name">{{ t('mother_name') }}</label><input id="mother_name" v-model="form.mother_name" type="text" maxlength="255" :class="input" /></div>
                    <div><label :class="label" for="guardian_name">{{ t('guardian') }}</label><input id="guardian_name" v-model="form.guardian_name" type="text" maxlength="255" :class="input" /></div>
                    <div><label :class="label" for="guardian_mobile">{{ t('mobile') }} *</label><input id="guardian_mobile" v-model="form.guardian_mobile" type="tel" inputmode="tel" maxlength="30" :class="input" required /><p v-if="form.errors.guardian_mobile" :class="err" role="alert">{{ form.errors.guardian_mobile }}</p></div>
                </div>

                <div><label :class="label" for="present_address">{{ t('address') }}</label><textarea id="present_address" v-model="form.present_address" rows="2" maxlength="1000" :class="[input, 'h-auto py-2']"></textarea></div>
                <div><label :class="label" for="previous_school">{{ t('previous_school') }}</label><input id="previous_school" v-model="form.previous_school" type="text" maxlength="255" :class="input" /></div>

                <p v-if="form.hasErrors && !Object.keys(form.errors).some((k) => k !== 'website')" class="text-sm text-red-600" role="alert">{{ Object.values(form.errors)[0] }}</p>

                <button type="submit" :disabled="form.processing" class="h-10 rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">{{ t('apply_submit') }}</button>
            </form>
        </div>
    </div>
</template>
