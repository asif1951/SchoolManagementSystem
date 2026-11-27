<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Bar from '@/Components/Teaching/Bar.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import MissingPlans from '@/Components/Teaching/MissingPlans.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: Array, session: String, filters: Object, plans: Object, seesAll: Boolean, teachers: Array, classes: Array, subjects: Array,
    missing: Array, plannedCount: Number, canSchedule: Boolean, canMakeMine: Boolean, approval: Boolean, flash: Object,
})
const t = useT('teaching')
const f = reactive({ session: props.session, ...props.filters })
// only the session changes what is missing; the other filters change the plan cards, so only those are asked for
const load = (changed) => router.get(route('teaching.plans'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)),
    { preserveScroll: true, preserveState: true, replace: true, ...(changed !== 'session' ? { only: ['plans', 'filters', 'flash'] } : {}) })

const mine = () => router.post(route('teaching.plans.make-mine'), { session: props.session }, { preserveScroll: true })

const task = ref(null)
const scheduleAll = async () => {
    try { task.value = (await window.axios.post(route('teaching.plans.schedule-all'), { session: props.session })).data.task }
    catch (e) { window.dispatchEvent(new CustomEvent('teaching:toast', { detail: { text: e.response?.data?.message || t('msg.failed'), type: 'error' } })) }
}
const scheduled = () => router.reload({ preserveScroll: true })
// two lists in one place; switching is instant, and the choice is remembered for this visit
const stored = () => { try { return sessionStorage.getItem('teaching.plans.tab') } catch { return null } }
const tab = ref(['planned', 'unplanned'].includes(stored()) ? stored() : (props.plannedCount || !props.missing.length ? 'planned' : 'unplanned'))
const show = (name) => { tab.value = name; try { sessionStorage.setItem('teaching.plans.tab', name) } catch { /* private window */ } }
const tabs = computed(() => [['planned', props.plannedCount], ['unplanned', props.missing.length]])
// arrow keys move between the two, as in any tab list
const step = (event) => { show(tab.value === 'planned' ? 'unplanned' : 'planned'); event.currentTarget.parentElement.querySelector(`#tab-${tab.value}`).focus() }
const title = computed(() => (props.seesAll ? t('plans.all') : t('plans.mine')))
</script>

<template>
    <Shell :title="title" :subtitle="t('hint.plans')">
        <template #actions>
            <select :aria-label="t('session')" id="p-year" v-model="f.session" class="field !w-auto" @change="load('session')"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
            <button v-if="canSchedule && plans.data.some((p) => p.health === 'none')" type="button" class="btn" @click="scheduleAll">{{ t('plans.schedule_all') }}</button>
        </template>

        <div v-if="task" class="panel mb-4 p-4"><TaskProgress :task-id="task" route-name="teaching.tasks.show" @finished="scheduled" /></div>

        <section class="panel overflow-hidden">
            <div class="grid grid-cols-2 border-b border-slate-200 dark:border-slate-800" role="tablist" :aria-label="title">
                <button v-for="[name, n] in tabs" :id="`tab-${name}`" :key="name" type="button" role="tab" :aria-selected="tab === name" :aria-controls="`panel-${name}`" :tabindex="tab === name ? 0 : -1"
                        class="flex items-center justify-center gap-2 border-b-4 px-3 py-3 text-sm font-semibold transition-colors"
                        :class="tab === name ? 'border-brand-700 bg-brand-50 text-brand-900 dark:bg-slate-800 dark:text-white' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white'"
                        @click="show(name)" @keydown.left.prevent="step" @keydown.right.prevent="step">
                    {{ t(`plans.tab_${name}`) }}
                    <span class="badge" :class="tab === name ? 'bg-brand-700 text-white' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'">{{ n }}</span>
                </button>
            </div>

            <!-- subjects without a plan: their own filters and list -->
            <div v-show="tab === 'unplanned'" id="panel-unplanned" role="tabpanel" aria-labelledby="tab-unplanned">
                <MissingPlans v-if="missing.length" :items="missing" :sees-all="seesAll" :can-make-mine="canMakeMine" :session="session" @mine="mine" />
                <p v-else class="px-4 py-12 text-center text-sm text-slate-500">✓ {{ t('missing.done') }}</p>
            </div>

            <!-- plans already made: their own filters and results -->
            <div v-show="tab === 'planned'" id="panel-planned" role="tabpanel" aria-labelledby="tab-planned">
            <div class="grid grid-cols-2 gap-3 border-b border-slate-200 p-4 lg:grid-cols-4 dark:border-slate-800">
                <div v-if="seesAll"><label class="field-label" for="p-teacher">{{ t('teacher') }}</label><select id="p-teacher" v-model="f.teacher" class="field" @change="load('teacher')"><option :value="null">{{ t('all') }}</option><option v-for="x in teachers" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
                <div><label class="field-label" for="p-class">{{ t('class') }}</label><select id="p-class" v-model="f.class" class="field" @change="load('class')"><option :value="null">{{ t('all') }}</option><option v-for="x in classes" :key="x.id" :value="x.id">{{ x.label }}</option></select></div>
                <div><label class="field-label" for="p-subject">{{ t('subject') }}</label><select id="p-subject" v-model="f.subject" class="field" @change="load('subject')"><option :value="null">{{ t('all') }}</option><option v-for="x in subjects" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
                <div><label class="field-label" for="p-health">{{ t('status_label') }}</label><select id="p-health" v-model="f.health" class="field" @change="load('health')"><option :value="null">{{ t('all') }}</option><option v-for="h in ['green', 'amber', 'red', 'none']" :key="h" :value="h">{{ t(`status.${h}`) }}</option></select></div>
            </div>

            <p v-if="!plans.data.length" class="px-4 py-12 text-center text-sm text-slate-500">{{ filters.class || filters.subject || filters.health || (seesAll && filters.teacher) ? t('missing.none') : missing.length ? t('plans.empty_make') : t('plans.empty') }}</p>

            <div v-else class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="p in plans.data" :key="p.id" :href="route('teaching.plans.show', p.id)" class="panel block p-4 transition-colors hover:border-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-700">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0"><div class="truncate font-semibold text-slate-900 dark:text-white">{{ p.subject }}</div><div class="truncate text-sm text-slate-500">{{ p.class }}<template v-if="seesAll"> · {{ p.teacher }}</template></div></div>
                        <Chip :value="p.health" :gap="p.gap" />
                    </div>
                    <div class="mt-3"><Bar :done="p.done" :expected="p.health === 'none' ? null : p.expected" :health="p.health" /></div>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                        <span>{{ p.chapters }} {{ t('chapters') }}</span>
                        <span v-if="p.delayed" class="font-semibold text-red-700">{{ p.delayed }} {{ t('status.delayed') }}</span>
                        <span v-if="p.finish">{{ t('finish_by') }} {{ p.finish }}</span>
                        <Chip v-if="approval" :value="p.status" />
                    </div>
                </Link>
            </div>

            <nav v-if="plans.prev || plans.next" class="flex items-center justify-center gap-3 pb-4 text-sm" :aria-label="t('pages')">
                <Link v-if="plans.prev" :href="plans.prev" class="btn" preserve-scroll>← {{ t('previous') }}</Link>
                <span class="text-slate-500">{{ plans.page }}</span>
                <Link v-if="plans.next" :href="plans.next" class="btn" preserve-scroll>{{ t('next') }} →</Link>
            </nav>
            </div>
        </section>
    </Shell>
</template>
