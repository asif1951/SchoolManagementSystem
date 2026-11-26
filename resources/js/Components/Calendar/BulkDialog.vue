<script setup>
/** Many dates at once: pasted lines or a spreadsheet, checked and shown before anything is saved. */
import Dialog from '@/Components/Teaching/Dialog.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { range } from '@/lib/calendar'

const props = defineProps({ types: Array })
const emit = defineEmits(['close'])
const text = ref('')
const file = ref(null)
const tentative = ref(false)
const rows = ref(null)
const error = ref('')
const busy = ref(false)
const good = computed(() => (rows.value || []).filter((r) => !r.error && !r.duplicate))
const typeLabel = (k) => props.types.find((x) => x.key === k)?.label

const check = async () => {
    busy.value = true; error.value = ''; rows.value = null
    const data = new FormData()
    if (file.value) data.append('file', file.value); else data.append('text', text.value)
    data.append('tentative', tentative.value ? 1 : 0)
    try { rows.value = (await window.axios.post(route('calendar.bulk.preview'), data)).data.rows }
    catch (e) { error.value = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : (e.response?.data?.message || 'The list could not be checked.') }
    busy.value = false
}
const save = () => {
    busy.value = true
    router.post(route('calendar.bulk.store'), { events: good.value.map((r) => r.data) }, { preserveScroll: true, onSuccess: () => emit('close'), onError: (e) => { error.value = Object.values(e)[0] }, onFinish: () => { busy.value = false } })
}
</script>

<template>
    <Dialog title="Add many dates" wide @close="emit('close')">
        <label class="field-label" for="bk-text">One date on each line: date | title | type | Bangla title</label>
        <textarea id="bk-text" v-model="text" rows="6" class="field font-mono text-sm" :disabled="!!file" placeholder="2026-02-21 | International Mother Language Day | Holiday&#10;14/06/2026 to 25/06/2026 | Summer vacation | Vacation&#10;১ বৈশাখ ১৪৩৩ | Pohela Boishakh | Holiday | পহেলা বৈশাখ" @input="rows = null"></textarea>
        <p class="mt-1 text-xs text-slate-500">Dates: 2026-02-21, 21/02/2026 or ৮ ফাল্গুন ১৪৩২. The type may be left out (it becomes Holiday).</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <span class="text-sm">or a file:</span>
            <input type="file" accept=".xlsx,.csv" class="field !w-auto" aria-label="Excel or CSV file" @change="file = $event.target.files[0] || null; rows = null" />
            <a :href="route('calendar.bulk.template')" class="text-sm font-medium text-brand-700 hover:underline">Download the template</a>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <label class="flex items-center gap-1.5 text-sm"><input v-model="tentative" type="checkbox" @change="rows = null" />Mark all as tentative</label>
            <button type="button" class="btn" :disabled="busy || (!text.trim() && !file)" @click="check">{{ busy && !rows ? 'Checking…' : 'Check' }}</button>
        </div>
        <p v-if="error" class="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-800" role="alert">{{ error }}</p>

        <template v-if="rows">
            <p class="mt-4 font-semibold">{{ good.length }} will be added · {{ rows.filter((r) => r.duplicate && !r.error).length }} already there · {{ rows.filter((r) => r.error).length }} with a problem</p>
            <div class="mt-2 max-h-64 overflow-auto rounded-md border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Line</th><th>Dates</th><th>Title</th><th>Type</th><th>Result</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.row">
                            <td class="muted">{{ r.row }}</td>
                            <td>{{ r.data ? range(r.data.starts_on, r.data.ends_on, 'both') : r.text }}</td>
                            <td>{{ r.data?.title }}</td><td>{{ r.data ? typeLabel(r.data.type) : '' }}</td>
                            <td :class="r.error ? 'text-red-700' : r.duplicate ? 'text-amber-700' : 'text-green-700'">{{ r.error || (r.duplicate ? 'Already there' : 'Ready') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="emit('close')">Cancel</button><button type="button" class="btn btn-primary" :disabled="busy || !good.length" @click="save">Add {{ good.length }} {{ good.length === 1 ? 'date' : 'dates' }}</button></div>
        </template>
    </Dialog>
</template>
