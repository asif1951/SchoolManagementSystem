<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import { Link, router } from '@inertiajs/vue3'
import { reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ settings: Object, abilities: Array, people: Array, canGrant: Boolean, flash: Object })
const t = useT('teaching')
const form = reactive({ ...props.settings })
const keep = { preserveScroll: true, preserveState: true }
const save = () => router.put(route('teaching.settings.save'), form, keep)

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
            <p class="mb-3 text-sm text-slate-500">{{ t('settings.holidays_help') }}</p>
            <Link href="/calendar" class="btn btn-primary">{{ t('settings.open_calendar') }}</Link>
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

    </Shell>
</template>
