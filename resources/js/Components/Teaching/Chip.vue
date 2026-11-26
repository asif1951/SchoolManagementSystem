<script setup>
/** A status in words, with a colour. kind: health (green|amber|red|none, with the gap), chapter status or plan status. */
import { computed } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ value: { type: String, required: true }, gap: { type: Number, default: null } })
const t = useT('teaching')

const green = 'border-green-300 bg-green-50 text-green-800'
const amber = 'border-amber-300 bg-amber-50 text-amber-900'
const red = 'border-red-300 bg-red-50 text-red-800'
const grey = 'border-slate-300 bg-slate-100 text-slate-700'
const violet = 'border-brand-300 bg-brand-50 text-brand-800'
const tone = { green, amber, red, none: grey, completed: green, running: violet, upcoming: grey, delayed: red, draft: grey, submitted: amber, approved: green, returned: red }

const label = computed(() => {
    const v = props.value
    if (props.gap === null || !['green', 'amber', 'red', 'none'].includes(v)) return t(`status.${v}`)
    if (v === 'none') return t('status.none')
    const g = Math.round(props.gap)
    if (v === 'green') return g > 0 ? `${t('status.ahead')} +${g}%` : t('status.on_track')
    return `${t('status.behind')} ${g}%`
})
</script>

<template><span class="inline-block whitespace-nowrap rounded border px-2 py-0.5 text-xs font-semibold" :class="tone[value] || grey">{{ label }}</span></template>
