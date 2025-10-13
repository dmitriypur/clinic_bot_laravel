<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'
import Datepicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import { ru } from 'date-fns/locale'

const totalSteps = 8
const step = ref(1)
const history = ref([1]) // история шагов

const city = ref(null)
const birthDate = ref(null)
const clinic = ref(null)
const branch = ref(null)
const doctor = ref(null)
const fio = ref('')
const phone = ref('')

const cities = ref([])
const clinics = ref([])
const branchesByClinic = ref({})
const expandedClinicId = ref(null)
const doctors = ref([])
const selectedDate = ref(new Date())
const minDate = ref(new Date())
const slots = ref([])
const selectedSlot = ref(null)
const isLoadingSlots = ref(false)
const datepickerLocale = ru
const hasAvailableSlots = computed(() => slots.value.some((slot) => slot.is_available))
const selectedDateLabel = computed(() => {
    const date = ensureDateInstance(selectedDate.value) || new Date()
    return date.toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    })
})

const resetDateTimeSelection = () => {
    const now = new Date()
    selectedDate.value = now
    minDate.value = now
    selectedSlot.value = null
    slots.value = []
    isLoadingSlots.value = false
}

const ensureDateInstance = (value) => {
    if (!value) {
        return null
    }
    return value instanceof Date ? value : new Date(value)
}

const formatDateForApi = (date) => {
    if (!date) {
        return null
    }
    const d = ensureDateInstance(date)
    const year = d.getFullYear()
    const month = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
}

const loadSlots = async () => {
    if (!doctor.value) {
        slots.value = []
        selectedSlot.value = null
        return
    }

    isLoadingSlots.value = true
    const previouslySelected = selectedSlot.value ? selectedSlot.value.datetime : null
    selectedSlot.value = null

    try {
        const { data } = await axios.get(`/api/v1/doctors/${doctor.value}/slots`, {
            params: {
                date: formatDateForApi(selectedDate.value),
                clinic_id: clinic.value || undefined,
                branch_id: branch.value || undefined,
            },
        })

        const slotData = Array.isArray(data.data) ? data.data : []
        slots.value = slotData
        if (previouslySelected) {
            const matchedSlot = slotData.find((slot) => slot.datetime === previouslySelected && slot.is_available)
            if (matchedSlot) {
                selectedSlot.value = matchedSlot
            }
        }
        if (!selectedSlot.value) {
            const firstAvailable = slotData.find((slot) => slot.is_available)
            selectedSlot.value = firstAvailable || null
        }
    } catch (error) {
        console.error('Не удалось загрузить слоты', error)
        slots.value = []
    } finally {
        isLoadingSlots.value = false
    }
}

const onDateChange = async (value) => {
    selectedDate.value = ensureDateInstance(value) || new Date()
    await loadSlots()
}

const selectSlot = (slot) => {
    if (!slot?.is_available) {
        return
    }
    selectedSlot.value = slot
}

const progress = computed(() => (step.value / totalSteps) * 100)

const goTo = (newStep) => {
    step.value = newStep
    history.value.push(newStep)
}

const goBack = () => {
    if (history.value.length > 1) {
        history.value.pop() // убираем текущий шаг
        step.value = history.value[history.value.length - 1]
    }

    if (step.value < 5) {
        clinics.value = []
        doctors.value = []
        clinic.value = null
        branchesByClinic.value = {}
        expandedClinicId.value = null
        branch.value = null
        doctor.value = null
        resetDateTimeSelection()
    }
}

const loadCities = async () => {
    let { data } = await axios.get('/api/v1/cities')
    cities.value = data.data
}

const loadClinics = async () => {
    let { data } = await axios.get(`/api/v1/cities/${city.value}/clinics`)
    clinics.value = data.data
    resetDateTimeSelection()
    branchesByClinic.value = {}
    branch.value = null
    expandedClinicId.value = null
}

const loadDoctors = async () => {
    resetDateTimeSelection()
    doctor.value = null
    let params = {}
    if (birthDate.value) params.birth_date = birthDate.value
    if (branch.value) params.branch_id = branch.value

    if(clinic.value){
        let { data } = await axios.get(`/api/v1/clinics/${clinic.value}/doctors`, { params })
        doctors.value = data.data
    }else{
        let { data } = await axios.get(`/api/v1/cities/${city.value}/doctors`, { params })
        doctors.value = data.data
    }

}

const selectClinic = async (chosenClinic) => {
    if (expandedClinicId.value === chosenClinic.id) {
        expandedClinicId.value = null
        clinic.value = null
        branch.value = null
        resetDateTimeSelection()
        doctors.value = []
        doctor.value = null
        return
    }

    clinic.value = chosenClinic.id
    branch.value = null
    expandedClinicId.value = chosenClinic.id
    doctors.value = []
    doctor.value = null

    if (!branchesByClinic.value[chosenClinic.id]) {
        try {
            const { data } = await axios.get(`/api/v1/clinics/${chosenClinic.id}/branches`, {
                params: {
                    city_id: city.value,
                },
            })

            branchesByClinic.value = {
                ...branchesByClinic.value,
                [chosenClinic.id]: Array.isArray(data.data) ? data.data : [],
            }
        } catch (error) {
            console.error('Не удалось загрузить филиалы', error)
            branchesByClinic.value = {
                ...branchesByClinic.value,
                [chosenClinic.id]: [],
            }
        }
    }

    const clinicBranches = branchesByClinic.value[chosenClinic.id] || []

    if (clinicBranches.length === 0) {
        await loadDoctors()
        goTo(6)
    } else if (clinicBranches.length === 1) {
        branch.value = clinicBranches[0].id
        await loadDoctors()
        goTo(6)
    }
}

const selectBranch = async (chosenClinicId, chosenBranch) => {
    clinic.value = chosenClinicId
    branch.value = chosenBranch.id
    doctors.value = []
    doctor.value = null
    await loadDoctors()
    goTo(6)
}

const selectDoctor = async (doctorItem) => {
    doctor.value = doctorItem.id
    await loadSlots()
    goTo(7)
}

const submit = async () => {
    await axios.post('/api/v1/applications', {
        city_id: city.value,
        clinic_id: clinic.value,
        branch_id: branch.value,
        doctor_id: doctor.value,
        cabinet_id: selectedSlot.value ? selectedSlot.value.cabinet_id : null,
        appointment_datetime: selectedSlot.value ? selectedSlot.value.datetime : null,
        full_name: fio.value,
        phone: phone.value,
        birth_date: birthDate.value,
    })
    alert('Вы успешно записаны!')
    step.value = 1
    history.value = [1]
    clinic.value = null
    branch.value = null
    branchesByClinic.value = {}
    expandedClinicId.value = null
    doctor.value = null
    doctors.value = []
    resetDateTimeSelection()
}

loadCities()
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 p-4">
        <div class="w-full max-w-md bg-white shadow-lg rounded-2xl p-6 space-y-6">

            <!-- Progress bar -->
            <div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div
                        class="bg-indigo-600 h-2 rounded-full transition-all duration-500"
                        :style="{ width: progress + '%' }"
                    ></div>
                </div>
                <p class="text-xs text-gray-500 mt-1 text-right">Шаг {{ step }} из {{ totalSteps }}</p>
            </div>

            <!-- Step 1 -->
            <div v-if="step === 1" class="text-center space-y-4">
                <h2 class="text-2xl font-bold text-gray-800">Онлайн-запись</h2>
                <button
                    @click="goTo(2)"
                    class="w-full py-3 bg-indigo-600 text-white font-medium rounded-xl hover:bg-indigo-700 transition"
                >
                    Записаться на приём
                </button>
            </div>

            <!-- Step 2 -->
            <div v-else-if="step === 2" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Выберите город</h3>
                <div class="grid grid-cols-1 gap-3">
                    <button
                        v-for="c in cities" :key="c.id"
                        @click="city = c.id; goTo(3)"
                        class="w-full py-2 bg-gray-100 rounded-lg hover:bg-indigo-100 transition"
                    >
                        {{ c.name }}
                    </button>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 3 -->
            <div v-else-if="step === 3" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Дата рождения (необязательно)</h3>
                <input
                    type="date"
                    v-model="birthDate"
                    class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-400"
                />
                <div class="flex gap-2">
                    <button @click="goTo(4)" class="flex-1 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Продолжить</button>
                    <button @click="birthDate = null; goTo(4)" class="flex-1 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">Пропустить</button>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 4 -->
            <div v-else-if="step === 4" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Выберите действие</h3>
                <div class="grid grid-cols-1 gap-3">
                    <button @click="loadClinics(); goTo(5)" class="w-full py-2 bg-gray-100 rounded-lg hover:bg-indigo-100">Выбрать клинику</button>
                    <button @click="loadDoctors().then(() => goTo(6))" class="w-full py-2 bg-gray-100 rounded-lg hover:bg-indigo-100">Выбрать доктора</button>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 5 -->
            <div v-else-if="step === 5" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Выберите клинику</h3>
                <div class="space-y-3">
                    <div
                        v-for="cl in clinics"
                        :key="cl.id"
                        class="rounded-lg border border-gray-200 bg-gray-50"
                    >
                        <button
                            type="button"
                            @click="selectClinic(cl)"
                            :class="[
                                'w-full flex justify-between items-center px-4 py-3 text-left rounded-lg transition',
                                clinic === cl.id ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-indigo-50'
                            ]"
                        >
                            <span class="font-medium text-gray-800">{{ cl.name }}</span>
                            <span class="text-xs text-gray-500">{{ expandedClinicId === cl.id ? 'Скрыть' : 'Филиалы' }}</span>
                        </button>

                        <div
                            v-if="expandedClinicId === cl.id"
                            class="border-t border-gray-200 px-4 py-3 space-y-2 bg-white rounded-b-lg"
                        >
                            <template v-if="(branchesByClinic[cl.id]?.length ?? 0) > 1">
                                <p class="text-sm text-gray-600">Выберите филиал:</p>
                                <div class="space-y-2">
                                    <button
                                        v-for="br in branchesByClinic[cl.id]"
                                        :key="br.id"
                                        type="button"
                                        @click.stop="selectBranch(cl.id, br)"
                                        :class="[
                                            'w-full text-left px-3 py-2 border rounded-lg transition',
                                            branch === br.id ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-gray-200 hover:bg-indigo-50'
                                        ]"
                                    >
                                        <span class="font-medium text-gray-800">{{ br.name }}</span>
                                        <span v-if="br.address" class="block text-xs text-gray-500 mt-1">{{ br.address }}</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <p class="text-sm text-gray-600">
                                    Филиал выбран автоматически.
                                </p>
                            </template>
                        </div>
                    </div>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 6 -->
            <div v-else-if="step === 6" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Доктора</h3>
                <div class="grid grid-cols-1 gap-3">
                    <p class="text-sm text-gray-500">С выбранными условиями найдено докторов: <span class="font-bold" :class="doctors.length > 0 ? 'text-green-500' : 'text-red-500'">{{ doctors.length }}</span></p>
                    <button
                        v-for="d in doctors" :key="d.id"
                        type="button"
                        @click="selectDoctor(d)"
                        :class="[
                            'w-full py-2 rounded-lg transition',
                            doctor === d.id ? 'bg-indigo-600 text-white hover:bg-indigo-600' : 'bg-gray-100 hover:bg-indigo-100'
                        ]"
                    >
                        {{ d.name }}
                    </button>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 7 -->
            <div v-else-if="step === 7" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Выберите дату и время</h3>

                <div v-if="!clinic" class="text-sm text-gray-500 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                    Чтобы выбрать время приёма, вернитесь и укажите клинику.
                </div>

                <div v-else class="space-y-4">
                    <Datepicker
                        v-model="selectedDate"
                        :min-date="minDate"
                        :enable-time-picker="false"
                        :auto-apply="true"
                        :locale="datepickerLocale"
                        :week-start="1"
                        @update:model-value="onDateChange"
                    />

                    <div>
                        <p class="text-sm text-gray-600 mb-2">
                            Свободные слоты на {{ selectedDateLabel }}
                        </p>
                        <div v-if="isLoadingSlots" class="text-sm text-gray-500">Загружаем слоты...</div>
                        <div v-else-if="slots.length === 0" class="text-sm text-gray-500">Смены врача на эту дату отсутствуют. Попробуйте выбрать другую дату.</div>
                        <div v-else class="grid grid-cols-2 gap-2">
                            <button
                                v-for="slot in slots"
                                :key="slot.id || slot.datetime"
                                type="button"
                                @click="selectSlot(slot)"
                                :class="[
                                    'py-2 rounded-lg border text-center transition',
                                    !slot.is_available
                                        ? 'border-gray-200 bg-gray-100 text-gray-400 cursor-not-allowed'
                                        : (selectedSlot && selectedSlot.datetime === slot.datetime
                                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                            : 'border-gray-200 hover:bg-indigo-100')
                                ]"
                                :disabled="!slot.is_available"
                            >
                                <span class="font-medium">{{ slot.time }}</span>
                                <span v-if="slot.branch_name || slot.cabinet_name" class="block text-xs text-gray-500">
                                    {{ slot.branch_name ? slot.branch_name + (slot.cabinet_name ? ', ' : '') : '' }}{{ slot.cabinet_name ?? '' }}
                                </span>
                                <span v-if="slot.is_occupied" class="block text-[10px] uppercase tracking-wide text-red-500">Занято</span>
                                <span v-else-if="slot.is_past" class="block text-[10px] uppercase tracking-wide text-gray-400">Прошло</span>
                            </button>
                        </div>
                        <p v-if="slots.length > 0 && !hasAvailableSlots" class="text-xs text-amber-600 mt-2">
                            Все слоты на выбранную дату заняты или уже прошли. Попробуйте выбрать другое число.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="w-full py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition disabled:bg-gray-300 disabled:cursor-not-allowed"
                        :disabled="!selectedSlot"
                        @click="goTo(8)"
                    >
                        Продолжить
                    </button>
                </div>

                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 8 -->
            <div v-else-if="step === 8" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Форма записи</h3>
                <div v-if="selectedSlot" class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700 space-y-1">
                    <p><span class="font-medium">Дата:</span> {{ selectedDateLabel }}</p>
                    <p><span class="font-medium">Время:</span> {{ selectedSlot.time }}</p>
                    <p v-if="selectedSlot.clinic_name"><span class="font-medium">Клиника:</span> {{ selectedSlot.clinic_name }}</p>
                    <p v-if="selectedSlot.branch_name"><span class="font-medium">Филиал:</span> {{ selectedSlot.branch_name }}</p>
                    <p v-if="selectedSlot.cabinet_name"><span class="font-medium">Кабинет:</span> {{ selectedSlot.cabinet_name }}</p>
                </div>
                <form @submit.prevent="submit" class="space-y-3">
                    <input v-model="fio" placeholder="ФИО" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-400" />
                    <input v-model="phone" placeholder="Телефон" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-400" />
                    <button type="submit" class="w-full py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Записаться</button>
                </form>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

        </div>
    </div>
</template>
