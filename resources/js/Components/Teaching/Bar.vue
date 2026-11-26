<script setup>
/** Done (filled) against expected by today (the marker). Colour is never the only signal: numbers are printed. */
import { useT } from '@/composables/useT'

defineProps({
    done: { type: Number, default: 0 },
    expected: { type: Number, default: null },
    health: { type: String, default: 'none' },
    compact: { type: Boolean, default: false },
})
const t = useT('teaching')
const fill = { green: 'bg-green-600', amber: 'bg-amber-500', red: 'bg-red-600', none: 'bg-brand-600' }
const n = (v) => Math.round(v)
</script>

<template>
    <div>
        <div v-if="!compact" class="mb-1 flex justify-between gap-2 text-xs text-slate-500">
            <span><b class="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{{ n(done) }}%</b> {{ t('done') }}</span>
            <span v-if="expected !== null" class="tabular-nums">{{ t('expected') }} {{ n(expected) }}%</span>
        </div>
        <div class="relative h-2.5 rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" :aria-valuenow="n(done)" aria-valuemin="0" aria-valuemax="100"
             :aria-label="`${t('done')} ${n(done)}%${expected !== null ? `, ${t('expected')} ${n(expected)}%` : ''}`">
            <div class="h-full rounded-full transition-all duration-300" :class="fill[health]" :style="{ width: Math.min(100, done) + '%' }"></div>
            <div v-if="expected !== null" class="absolute -top-0.5 h-3.5 w-0.5 rounded bg-slate-900 dark:bg-white" :style="{ left: `calc(${Math.min(100, expected)}% - 1px)` }" aria-hidden="true"></div>
        </div>
    </div>
</template>
