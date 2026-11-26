<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Bar from '@/Components/Teaching/Bar.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: Array, session: String, filters: Object, plans: Object, seesAll: Boolean, teachers: Array, classes: Array, subjects: Array,
    missing: Array, canSchedule: Boolean, canMakeMine: Boolean, approval: Boolean, flash: Object,
})
const t = useT('teaching')
const f = reactive({ session: props.session, ...props.filters })
const load = () => router.get(route('teaching.plans'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveScroll: true, preserveState: true, replace: true })

const making = ref(null)
const make = (m) => { making.value = `${m.class_id}-${m.subject_id}-${m.teacher_id}`; router.post(route('teaching.plans.store'), { class_id: m.class_id, subject_id: m.subject_id, teacher_id: m.teacher_id }, { onFinish: () => { making.value = null } }) }

const mine = () => router.post(route('teaching.plans.make-mine'), { session: props.session }, { preserveScroll: true })

const task = ref(null)
const scheduleAll = async () => {
    try { task.value = (await window.axios.post(route('teaching.plans.schedule-all'), { session: props.session })).data.task }
    catch (e) { window.dispatchEvent(new CustomEvent('teaching:toast', { detail: { text: e.response?.data?.message || t('msg.failed'), type: 'error' } })) }
}
const scheduled = () => router.reload({ preserveScroll: true })
const title = computed(() => (props.seesAll ? t('plans.all') : t('plans.mine')))
</script>

<template>
    <Shell :title="title" :subtitle="t('hint.plans')">
        <template #actions>
            <button v-if="canSchedule && plans.data.some((p) => p.health === 'none')" type="button" class="btn" @click="scheduleAll">{{ t('plans.schedule_all') }}</button>
        </template>

        <div v-if="task" class="panel mb-4 p-4"><TaskProgress :task-id="task" route-name="teaching.tasks.show" @finished="scheduled" /></div>

        <section class="panel mb-4 grid grid-cols-2 gap-3 p-4 lg:grid-cols-5">
            <div><label class="field-label" for="p-year">{{ t('session') }}</label><select id="p-year" v-model="f.session" class="field" @change="load"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
            <div v-if="seesAll"><label class="field-label" for="p-teacher">{{ t('teacher') }}</label><select id="p-teacher" v-model="f.teacher" class="field" @change="load"><option :value="null">{{ t('all') }}</option><option v-for="x in teachers" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
            <div><label class="field-label" for="p-class">{{ t('class') }}</label><select id="p-class" v-model="f.class" class="field" @change="load"><option :value="null">{{ t('all') }}</option><option v-for="x in classes" :key="x.id" :value="x.id">{{ x.label }}</option></select></div>
            <div><label class="field-label" for="p-subject">{{ t('subject') }}</label><select id="p-subject" v-model="f.subject" class="field" @change="load"><option :value="null">{{ t('all') }}</option><option v-for="x in subjects" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
            <div><label class="field-label" for="p-health">{{ t('status_label') }}</label><select id="p-health" v-model="f.health" class="field" @change="load"><option :value="null">{{ t('all') }}</option><option v-for="h in ['green', 'amber', 'red', 'none']" :key="h" :value="h">{{ t(`status.${h}`) }}</option></select></div>
        </section>

        <section v-if="missing.length" class="panel mb-4 border-l-4 !border-l-brand-700 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">{{ t('plans.missing') }}</h2>
                <button v-if="canMakeMine" type="button" class="btn btn-primary" @click="mine">{{ t('now.make_mine') }}</button>
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
                <button v-for="m in missing" :key="`${m.class_id}-${m.subject_id}-${m.teacher_id}`" type="button" class="btn" :disabled="making !== null" @click="make(m)">
                    + {{ m.subject }} · {{ m.class }}<span v-if="seesAll" class="text-slate-400"> · {{ m.teacher }}</span>
                </button>
            </div>
        </section>

        <p v-if="!plans.data.length" class="panel px-4 py-12 text-center text-sm text-slate-500">{{ missing.length ? t('plans.empty_make') : t('plans.empty') }}</p>

        <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
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

        <nav v-if="plans.prev || plans.next" class="mt-4 flex items-center justify-center gap-3 text-sm" :aria-label="t('pages')">
            <Link v-if="plans.prev" :href="plans.prev" class="btn" preserve-scroll>← {{ t('previous') }}</Link>
            <span class="text-slate-500">{{ plans.page }}</span>
            <Link v-if="plans.next" :href="plans.next" class="btn" preserve-scroll>{{ t('next') }} →</Link>
        </nav>
    </Shell>
</template>
