<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const current = computed(() => page.props.locale || 'en')

const choose = (locale) => {
    if (locale !== current.value) {
        router.post(route('locale'), { locale }, { preserveScroll: true, preserveState: false })
    }
}
</script>

<template>
    <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-xs font-semibold dark:border-slate-700" role="group" aria-label="Language">
        <button
            type="button"
            class="px-2.5 py-1.5"
            :class="current === 'en' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300'"
            @click="choose('en')"
        >English</button>
        <button
            type="button"
            class="border-l border-slate-300 px-2.5 py-1.5 dark:border-slate-700"
            :class="current === 'bn' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300'"
            @click="choose('bn')"
        >বাংলা</button>
    </div>
</template>
