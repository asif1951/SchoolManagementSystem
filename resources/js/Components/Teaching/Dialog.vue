<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '@/composables/useT'

defineProps({ title: { type: String, required: true }, wide: { type: Boolean, default: false } })
const emit = defineEmits(['close'])
const t = useT('teaching')
const box = ref(null)
let before = null
const onKey = (e) => { if (e.key === 'Escape') emit('close') }

onMounted(() => {
    before = document.activeElement
    window.addEventListener('keydown', onKey)
    nextTick(() => (box.value?.querySelector('input, select, textarea') || box.value)?.focus())
})
onBeforeUnmount(() => { window.removeEventListener('keydown', onKey); before?.focus?.() })
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/50 sm:items-start sm:p-8" @mousedown.self="emit('close')">
        <div ref="box" tabindex="-1" class="max-h-full w-full overflow-y-auto rounded-t-lg bg-white shadow-xl sm:rounded-lg dark:bg-slate-900" :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-md'" role="dialog" aria-modal="true" :aria-label="title">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ title }}</h2>
                <button type="button" class="text-xl leading-none text-slate-400 hover:text-slate-700" :aria-label="t('close')" @click="emit('close')">×</button>
            </div>
            <div class="px-5 py-4 text-sm text-slate-700 dark:text-slate-300"><slot /></div>
        </div>
    </div>
</template>
