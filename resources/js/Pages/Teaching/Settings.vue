<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import { router } from '@inertiajs/vue3'
import { reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ settings: Object, holidays: Array, abilities: Array, people: Array, canGrant: Boolean, flash: Object })
const t = useT('teaching')
const form = reactive({ ...props.settings })
const keep = { preserveScroll: true, preserveState: true }
const save = () => router.put(route('teaching.settings.save'), form, keep)

const h = reactive({ title: '', starts_on: '', ends_on: '' })
const addHoliday = () => router.post(route('teaching.settings.holidays'), { ...h, ends_on: h.ends_on || h.starts_on }, { ...keep, onSuccess: () => Object.assign(h, { title: '', starts_on: '', ends_on: '' }) })
const removing = ref(null)
const removeHoliday = () => router.delete(route('teaching.settings.holidays.destroy', removing.value.id), { ...keep, onSuccess: () => { removing.value = null } })

const person = ref('')
const granted = ref([])
watch(person, (id) => { granted.value = [...(props.people.find((p) => p.id === Number(id))?.extra ?? [])].filter((a) => a.startsWith('teaching.')) })
const grant = () => router.put(route('teaching.settings.grant', person.value), { abilities: granted.value }, keep)
</script>

<template>
    <Shell :title="t('nav.settings')" :subtitle="t('hint.settings')" :wide="false">
        <form class="panel mb-4 space-y-4 p-4" @submit.prevent="save">
            <label class="flex items-start gap-2"><input v-model="form.approval_required" type="checkbox" class="mt-1" /><span><b>{{ t('settings.approval') }}</b><span class="block text-xs text-slate-500">{{ t('settings.approval_help') }}</span></span></label>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label class="field-label" for="st-win">{{ t('settings.window') }}</label><input id="st-win" v-model.number="form.edit_window_days" type="number" min="0" max="365" class="field" /></div>
                <div><label class="field-label" for="st-am">{{ t('settings.amber') }}</label><input id="st-am" v-model.number="form.amber_gap" type="number" min="0" max="100" class="field" /></div>
                <div><label class="field-label" for="st-red">{{ t('settings.red') }}</label><input id="st-red" v-model.number="form.red_gap" type="number" min="0" max="100" class="field" /></div>
            </div>
            <div class="text-right"><button class="btn btn-primary">{{ t('save') }}</button></div>
        </form>

        <section class="panel mb-4 p-4">
            <h2 class="font-semibold">{{ t('settings.holidays') }}</h2>
            <p class="mb-3 text-xs text-slate-500">{{ t('settings.holidays_help') }}</p>
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-4" @submit.prevent="addHoliday">
                <input v-model="h.title" required maxlength="120" class="field" :placeholder="t('settings.holiday_name')" :aria-label="t('settings.holiday_name')" />
                <input v-model="h.starts_on" required type="date" class="field" :aria-label="t('from')" />
                <input v-model="h.ends_on" type="date" class="field" :min="h.starts_on" :aria-label="t('to')" />
                <button class="btn btn-primary">{{ t('add') }}</button>
            </form>
            <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <li v-for="x in holidays" :key="x.id" class="flex items-center justify-between gap-2 py-2"><span><b>{{ x.title }}</b> · {{ x.starts_on }}<template v-if="x.ends_on !== x.starts_on"> – {{ x.ends_on }}</template></span><button type="button" class="text-red-700 hover:underline" @click="removing = x">{{ t('delete') }}</button></li>
                <li v-if="!holidays.length" class="py-3 text-slate-500">{{ t('settings.no_holidays') }}</li>
            </ul>
        </section>

        <section v-if="canGrant" class="panel p-4">
            <h2 class="font-semibold">{{ t('settings.permissions') }}</h2>
            <p class="mb-3 text-xs text-slate-500">{{ t('settings.permissions_help') }}</p>
            <select v-model="person" class="field" :aria-label="t('settings.person')"><option value="">{{ t('settings.person') }}</option><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }} ({{ p.role }}){{ p.extra.length ? ' *' : '' }}</option></select>
            <div v-if="person" class="mt-3 space-y-1.5">
                <label v-for="a in abilities" :key="a.key" class="flex items-center gap-2 text-sm"><input v-model="granted" type="checkbox" :value="a.key" />{{ a.label }}</label>
                <div class="text-right"><button type="button" class="btn btn-primary" @click="grant">{{ t('save') }}</button></div>
            </div>
        </section>

        <Dialog v-if="removing" :title="t('delete')" @close="removing = null">
            <p>{{ t('settings.remove_holiday', { title: removing.title }) }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="removing = null">{{ t('cancel') }}</button><button type="button" class="btn btn-danger" @click="removeHoliday">{{ t('delete') }}</button></div>
        </Dialog>
    </Shell>
</template>
