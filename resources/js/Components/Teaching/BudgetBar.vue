<script setup>
/** The periods the year holds against the periods planned, live while typing, with the Save button beside it. */
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useT } from '@/composables/useT'

const props = defineProps({
    limit: { type: Number, default: null }, planned: { type: Number, required: true }, year: { type: String, required: true },
    note: { type: String, default: '' }, dirty: Boolean, busy: Boolean, canSave: Boolean,
})
defineEmits(['save', 'reset'])
const t = useT('teaching')
const page = usePage()
// the link is for those who can open the routine screens
const routine = computed(() => (page.props.auth?.abilities ?? []).some((a) => a === '*' || a === 'routine.manage'))
const left = computed(() => (props.limit === null ? null : props.limit - props.planned))
const state = computed(() => (left.value === null ? 'none' : left.value < 0 ? 'over' : left.value > 0 ? 'short' : 'exact'))
const look = {
    none: 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900', over: 'border-red-400 bg-red-50 dark:border-red-800 dark:bg-red-950',
    short: 'border-yellow-400 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950', exact: 'border-green-500 bg-green-50 dark:border-green-800 dark:bg-green-950',
}
</script>

<template>
    <section class="sticky top-0 z-20 mb-3 rounded-lg border-2 p-3 text-slate-900 shadow-sm dark:text-slate-100" :class="look[state]" role="status" aria-live="polite">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <template v-if="limit !== null">
                <p><span class="block text-xs text-slate-600 dark:text-slate-300">{{ t('budget.available', { year }) }}</span><b class="text-2xl tabular-nums">{{ limit }}</b></p>
                <p><span class="block text-xs text-slate-600 dark:text-slate-300">{{ t('budget.planned') }}</span><b class="text-2xl tabular-nums">{{ planned }}</b></p>
                <p class="text-base font-semibold">
                    <template v-if="state === 'over'">✕ {{ t('budget.extra', { n: -left }) }}</template>
                    <template v-else-if="state === 'short'">○ {{ t('budget.left', { n: left }) }}</template>
                    <template v-else>✓ {{ t('budget.exact') }}</template>
                </p>
            </template>
            <template v-else>
                <p><span class="block text-xs text-slate-600 dark:text-slate-300">{{ t('budget.planned') }}</span><b class="text-2xl tabular-nums">{{ planned }}</b></p>
            </template>
            <span class="flex-1" />
            <template v-if="canSave">
                <button v-if="dirty" type="button" class="btn" :disabled="busy" @click="$emit('reset')">{{ t('budget.undo') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !dirty" @click="$emit('save')">{{ t('budget.save') }}</button>
            </template>
        </div>
        <p v-if="state === 'over'" class="mt-1 text-sm text-red-800 dark:text-red-200">{{ t('budget.over', { limit, year, planned, extra: -left }) }}</p>
        <p v-if="limit === null" class="mt-2 rounded-md border border-brand-300 bg-brand-50 px-3 py-2 text-sm font-medium text-brand-900 dark:border-brand-700 dark:bg-slate-800 dark:text-brand-100">ⓘ {{ t('budget.publish') }}
            <Link v-if="routine" :href="route('routine.index')" class="ml-1 underline">{{ t('budget.open_routine') }}</Link></p>
        <p v-if="note" class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ note }}</p>
    </section>
</template>
