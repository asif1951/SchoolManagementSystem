<script setup>
/** One date, typed in either calendar: changing the English date fills the Bangla one and the other way round. */
import { computed } from 'vue'
import { MONTHS, digits, monthLengths, toBangla, toGregorian } from '@/lib/bangla'

const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, required: true }, id: { type: String, required: true }, required: Boolean, min: { type: String, default: undefined } })
const emit = defineEmits(['update:modelValue'])

const bn = computed(() => (/^\d{4}-\d{2}-\d{2}$/.test(props.modelValue) ? toBangla(props.modelValue) : null))
const years = computed(() => { const mid = bn.value?.year ?? toBangla(new Date().toISOString().slice(0, 10)).year; return Array.from({ length: 11 }, (_, i) => mid - 5 + i) })
const days = computed(() => (bn.value ? monthLengths(bn.value.year)[bn.value.month - 1] : 31))
const set = (part, value) => {
    const now = bn.value ?? toBangla(new Date().toISOString().slice(0, 10))
    const next = { ...now, [part]: Number(value) }
    emit('update:modelValue', toGregorian(next.year, next.month, next.day))
}
</script>

<template>
    <fieldset>
        <legend class="field-label">{{ label }}</legend>
        <input :id="id" type="date" class="field" :value="modelValue" :required="required" :min="min" :aria-label="`${label}, English calendar`" @input="emit('update:modelValue', $event.target.value)" />
        <div class="mt-1.5 grid grid-cols-[1fr_1.6fr_1.2fr] gap-1.5" lang="bn">
            <select class="field !px-2" :value="bn?.day ?? ''" :aria-label="`${label}, বাংলা দিন`" @change="set('day', $event.target.value)"><option v-if="!bn" value="">দিন</option><option v-for="d in days" :key="d" :value="d">{{ digits(d) }}</option></select>
            <select class="field !px-2" :value="bn?.month ?? ''" :aria-label="`${label}, বাংলা মাস`" @change="set('month', $event.target.value)"><option v-if="!bn" value="">মাস</option><option v-for="(m, i) in MONTHS" :key="m" :value="i + 1">{{ m }}</option></select>
            <select class="field !px-2" :value="bn?.year ?? ''" :aria-label="`${label}, বঙ্গাব্দ`" @change="set('year', $event.target.value)"><option v-if="!bn" value="">বছর</option><option v-for="y in years" :key="y" :value="y">{{ digits(y) }}</option></select>
        </div>
    </fieldset>
</template>
