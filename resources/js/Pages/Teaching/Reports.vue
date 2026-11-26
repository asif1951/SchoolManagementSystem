<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ report: String, filters: Object, page: Number, years: Array, headings: Object, dated: Boolean, rows: Array, more: Boolean, seesAll: Boolean, teachers: Array, classes: Array, subjects: Array })
const t = useT('teaching')
const f = reactive({ ...props.filters })
const params = (more = {}) => Object.fromEntries(Object.entries({ report: props.report, ...f, ...more }).filter(([, v]) => v))
const load = (more = {}) => { task.value = null; router.get(route('teaching.reports'), params(more), { preserveScroll: true }) }
const keys = computed(() => Object.keys(props.headings))

const task = ref(null)
const file = ref(null)
const exportIt = async () => {
    file.value = null
    try { task.value = (await window.axios.post(route('teaching.reports.export'), params())).data.task }
    catch (e) { window.dispatchEvent(new CustomEvent('teaching:toast', { detail: { text: e.response?.status === 429 ? t('report.too_many') : t('msg.failed'), type: 'error' } })) }
}
const finished = (x) => { if (x.status === 'done') file.value = route('teaching.tasks.download', x.id) }
</script>

<template>
    <Shell :title="t('nav.reports')" :subtitle="t('hint.reports')">
        <template #actions>
            <a :href="route('teaching.reports.print', params())" target="_blank" rel="noopener" class="btn">{{ t('period.print_pdf') }}</a>
            <button type="button" class="btn btn-primary" @click="exportIt">{{ t('report.excel') }}</button>
        </template>

        <div class="mb-3 flex flex-wrap gap-1" role="tablist">
            <button v-for="r in ['progress', 'pending', 'delayed', 'completion', 'activity']" :key="r" type="button" role="tab" :aria-selected="report === r" class="rounded px-3 py-1.5 text-sm font-medium" :class="report === r ? 'bg-brand-700 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:border-brand-500 dark:bg-slate-900 dark:text-slate-200'" @click="load({ report: r, page: undefined })">{{ t(`report.${r}`) }}</button>
        </div>

        <section class="panel mb-4 grid grid-cols-2 gap-3 p-4 lg:grid-cols-5">
            <template v-if="dated">
                <div><label class="field-label" for="r-from">{{ t('from') }}</label><input id="r-from" v-model="f.from" type="date" class="field" :max="f.to" @change="load()" /></div>
                <div><label class="field-label" for="r-to">{{ t('to') }}</label><input id="r-to" v-model="f.to" type="date" class="field" @change="load()" /></div>
            </template>
            <div v-else><label class="field-label" for="r-year">{{ t('session') }}</label><select id="r-year" v-model="f.session" class="field" @change="load()"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
            <div v-if="seesAll"><label class="field-label" for="r-teacher">{{ t('teacher') }}</label><select id="r-teacher" v-model="f.teacher" class="field" @change="load()"><option :value="null">{{ t('all') }}</option><option v-for="x in teachers" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
            <template v-if="!dated">
                <div><label class="field-label" for="r-class">{{ t('class') }}</label><select id="r-class" v-model="f.class" class="field" @change="load()"><option :value="null">{{ t('all') }}</option><option v-for="x in classes" :key="x.id" :value="x.id">{{ x.label }}</option></select></div>
                <div><label class="field-label" for="r-subject">{{ t('subject') }}</label><select id="r-subject" v-model="f.subject" class="field" @change="load()"><option :value="null">{{ t('all') }}</option><option v-for="x in subjects" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
            </template>
        </section>

        <div v-if="task" class="panel mb-4 p-4">
            <TaskProgress :task-id="task" route-name="teaching.tasks.show" @finished="finished" />
            <a v-if="file" :href="file" class="btn btn-primary mt-3">{{ t('report.download') }}</a>
        </div>

        <section class="panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[640px]">
                    <thead><tr><th v-for="k in keys" :key="k">{{ headings[k] }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="i"><td v-for="k in keys" :key="k">{{ r[k] ?? '—' }}</td></tr>
                        <tr v-if="!rows.length"><td :colspan="keys.length" class="px-4 py-10 text-center text-slate-500">{{ t('report.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <nav v-if="page > 1 || more" class="flex items-center justify-center gap-3 p-3 text-sm" :aria-label="t('pages')">
                <button type="button" class="btn" :disabled="page <= 1" @click="load({ page: page - 1 })">← {{ t('previous') }}</button><span>{{ page }}</span><button type="button" class="btn" :disabled="!more" @click="load({ page: page + 1 })">{{ t('next') }} →</button>
            </nav>
        </section>
    </Shell>
</template>
