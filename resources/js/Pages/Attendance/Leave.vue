<script setup>
import Shell from '@/Components/Attendance/Shell.vue'
import Dialog from '@/Components/Attendance/Dialog.vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useT } from '@/composables/useT'
import { SOFT, day, num, useCalendarMode } from '@/lib/attendance'

const props = defineProps({ leaves: Object, counts: Object, types: Array, filters: Object, canApply: Boolean })
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)
const search = ref(props.filters.search)
const list = (params) => router.get(route('attendance.leave'), params, { preserveState: true, preserveScroll: true })
const look = { pending: SOFT.late, approved: SOFT.present, rejected: SOFT.absent, cancelled: SOFT.leave }

// ---- new application -------------------------------------------------------
const adding = ref(false)
const query = ref('')
const found = ref([])
const picked = ref(null)
const form = useForm({ student_id: null, type: props.types[0], starts_on: '', ends_on: '', reason: '', attachment: null })
let timer = null
const find = () => {
    clearTimeout(timer)
    timer = setTimeout(async () => { found.value = query.value.trim() ? (await window.axios.get(route('attendance.leave.students'), { params: { q: query.value } })).data : [] }, 300)
}
const choose = (s) => { picked.value = s; form.student_id = s.id; found.value = []; query.value = '' }
const startAdd = () => { form.reset(); form.clearErrors(); picked.value = null; adding.value = true }
const send = () => form.post(route('attendance.leave.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { adding.value = false } })

// ---- decisions --------------------------------------------------------------
const deciding = ref(null)
const decision = useForm({ status: '', remark: '' })
const ask = (leave, status) => { decision.status = status; decision.remark = ''; decision.clearErrors(); deciding.value = leave }
const decide = () => decision.post(route('attendance.leave.decide', deciding.value.id), { preserveScroll: true, onSuccess: () => { deciding.value = null } })
const label = { approved: 'approve', rejected: 'reject', cancelled: 'cancel_leave' }
</script>

<template>
    <Shell :title="t('leave_title')" :subtitle="t('leave_sub')">
        <template #actions><button v-if="canApply" type="button" class="btn btn-primary" @click="startAdd">+ {{ t('new_leave') }}</button></template>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900" role="tablist">
                <button v-for="s in ['pending', 'approved', 'rejected', 'all']" :key="s" type="button" role="tab" :aria-selected="!filters.student && filters.status === s" class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="!filters.student && filters.status === s ? 'bg-brand-700 text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-800'" @click="list({ status: s, search: search || undefined })">{{ t(`status_leave.${s}`) }}<span v-if="s !== 'all' && counts[s]" class="ml-1 opacity-75">{{ n(counts[s]) }}</span></button>
            </div>
            <form class="flex gap-2" @submit.prevent="list({ status: filters.status, search: search || undefined })"><input v-model="search" type="search" class="field !w-48" :placeholder="t('find_student')" :aria-label="t('search')"><button type="submit" class="btn">{{ t('search') }}</button></form>
            <button v-if="filters.student" type="button" class="btn" @click="list({ status: 'pending' })">× {{ t('history_student') }}</button>
        </div>

        <p v-if="!leaves.data.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('no_leave') }}</p>
        <ul class="space-y-2">
            <li v-for="l in leaves.data" :key="l.id" class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ l.student }} <span class="font-normal text-slate-500">· {{ l.class }} · {{ t('col.roll') }} {{ l.roll ? n(l.roll) : '—' }}</span></p>
                        <p><span class="badge mr-1" :class="look[l.status]">{{ t(`status_leave.${l.status}`) }}</span>{{ l.type }} · {{ day(l.from, mode) }}<template v-if="l.to !== l.from"> – {{ day(l.to, mode) }}</template></p>
                        <p class="mt-1 whitespace-pre-line">{{ l.reason }}</p>
                        <p v-if="l.remark" class="mt-1 text-slate-500">“{{ l.remark }}”</p>
                        <p class="mt-1 text-xs text-slate-500">{{ t('applied_by', { name: l.applied_by || '—' }) }}<template v-if="l.decided_by"> · {{ t('decided_by', { name: l.decided_by }) }}</template>
                            <a v-if="l.attachment" class="ml-2 underline" :href="route('attendance.leave.attachment', l.id)">📎 {{ l.attachment }}</a>
                            <button type="button" class="ml-2 underline" @click="list({ student: l.student_id })">{{ t('history_student') }}</button></p>
                    </div>
                    <div v-if="l.can_decide" class="flex gap-2">
                        <template v-if="l.status === 'pending'"><button type="button" class="btn btn-primary" @click="ask(l, 'approved')">✓ {{ t('approve') }}</button><button type="button" class="btn btn-danger" @click="ask(l, 'rejected')">✕ {{ t('reject') }}</button></template>
                        <button v-else-if="l.status === 'approved'" type="button" class="btn" @click="ask(l, 'cancelled')">{{ t('cancel_leave') }}</button>
                    </div>
                </div>
            </li>
        </ul>
        <nav v-if="leaves.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
            <Link v-if="leaves.prev_page_url" class="btn" :href="leaves.prev_page_url" preserve-scroll>← {{ t('previous') }}</Link><span v-else />
            <span>{{ t('page_of', { page: n(leaves.current_page), pages: n(leaves.last_page) }) }}</span>
            <Link v-if="leaves.next_page_url" class="btn" :href="leaves.next_page_url" preserve-scroll>{{ t('next') }} →</Link><span v-else />
        </nav>

        <Dialog v-if="adding" :title="t('new_leave')" @close="adding = false">
            <form class="space-y-3" @submit.prevent="send">
                <div>
                    <span class="field-label">{{ t('student') }}</span>
                    <p v-if="picked" class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 dark:border-slate-700"><span>{{ picked.name }} · {{ picked.class }} · {{ picked.roll }}</span><button type="button" :aria-label="t('remove')" @click="picked = null; form.student_id = null">×</button></p>
                    <template v-else>
                        <input v-model="query" type="search" class="field" :placeholder="t('find_student')" :aria-label="t('student')" @input="find">
                        <ul v-if="found.length" class="mt-1 max-h-40 overflow-y-auto rounded-md border border-slate-200 dark:border-slate-700"><li v-for="s in found" :key="s.id"><button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-brand-50 dark:hover:bg-slate-800" @click="choose(s)">{{ s.name }} · {{ s.class }} · {{ s.roll }}</button></li></ul>
                    </template>
                    <p v-if="form.errors.student_id" class="text-xs text-red-700">{{ form.errors.student_id }}</p>
                </div>
                <label class="block"><span class="field-label">{{ t('type') }}</span><select v-model="form.type" class="field"><option v-for="x in types" :key="x" :value="x">{{ x }}</option></select></label>
                <div class="grid grid-cols-2 gap-2">
                    <label><span class="field-label">{{ t('starts') }}</span><input v-model="form.starts_on" type="date" required class="field" @change="form.ends_on ||= form.starts_on"></label>
                    <label><span class="field-label">{{ t('ends') }}</span><input v-model="form.ends_on" type="date" required class="field" :min="form.starts_on"></label>
                </div>
                <label class="block"><span class="field-label">{{ t('reason') }}</span><textarea v-model="form.reason" required maxlength="500" rows="3" class="field" /></label>
                <label class="block"><span class="field-label">{{ t('attachment') }}</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm" @change="form.attachment = $event.target.files[0] ?? null"></label>
                <p v-for="(e, k) in form.errors" :key="k" class="text-xs text-red-700">{{ e }}</p>
                <div class="flex justify-end gap-2"><button type="button" class="btn" @click="adding = false">{{ t('cancel') }}</button><button type="submit" class="btn btn-primary" :disabled="form.processing || !form.student_id">{{ t('apply') }}</button></div>
            </form>
        </Dialog>

        <Dialog v-if="deciding" :title="t(label[decision.status])" @close="deciding = null">
            <p>{{ deciding.student }} · {{ day(deciding.from, mode) }} – {{ day(deciding.to, mode) }}</p>
            <label class="mt-3 block"><span class="field-label">{{ t('decide_remark') }}</span><textarea v-model="decision.remark" rows="2" maxlength="500" class="field" /></label>
            <p v-for="(e, k) in decision.errors" :key="k" class="text-xs text-red-700">{{ e }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="deciding = null">{{ t('close') }}</button><button type="button" class="btn btn-primary" :disabled="decision.processing" @click="decide">{{ t('confirm') }}</button></div>
        </Dialog>
    </Shell>
</template>
