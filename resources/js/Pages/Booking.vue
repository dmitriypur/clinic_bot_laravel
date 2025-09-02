<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'

const step = ref(1)
const history = ref([1]) // история шагов

const city = ref(null)
const birthDate = ref(null)
const clinic = ref(null)
const doctor = ref(null)
const fio = ref('')
const phone = ref('')

const cities = ref([])
const clinics = ref([])
const doctors = ref([])

const progress = computed(() => (step.value / 7) * 100)

const goTo = (newStep) => {
    step.value = newStep
    history.value.push(newStep)
}

const goBack = () => {
    if (history.value.length > 1) {
        history.value.pop() // убираем текущий шаг
        step.value = history.value[history.value.length - 1]
    }

    if(step.value < 5){
        clinics.value = []
        doctors.value = []
        clinic.value = null
    }
}

const loadCities = async () => {
    let { data } = await axios.get('/api/v1/cities')
    cities.value = data.data
}

const loadClinics = async () => {
    let { data } = await axios.get(`/api/v1/cities/${city.value}/clinics`)
    clinics.value = data.data
}

const loadDoctors = async () => {
    let params = {}
    if (birthDate.value) params.birth_date = birthDate.value

    if(clinic.value){
        let { data } = await axios.get(`/api/v1/clinics/${clinic.value}/doctors`, { params })
        console.log(data)
        doctors.value = data.data
    }else{
        let { data } = await axios.get(`/api/v1/cities/${city.value}/doctors`, { params })
        doctors.value = data.data
    }

}

const submit = async () => {
    console.log(city.value)
    console.log(clinic.value)
    console.log(doctor.value)
    console.log(fio.value)
    console.log(phone.value)
    console.log(birthDate.value)
    await axios.post('/api/v1/applications', {
        city_id: city.value,
        clinic_id: clinic.value,
        doctor_id: doctor.value,
        full_name: fio.value,
        phone: phone.value,
        birth_date: birthDate.value,
    })
    alert('Вы успешно записаны!')
    step.value = 1
    history.value = [1]
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
                <p class="text-xs text-gray-500 mt-1 text-right">Шаг {{ step }} из 7</p>
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
                    <button @click="loadDoctors(); goTo(6)" class="w-full py-2 bg-gray-100 rounded-lg hover:bg-indigo-100">Выбрать доктора</button>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 5 -->
            <div v-else-if="step === 5" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Клиники</h3>
                <div class="grid grid-cols-1 gap-3">
                    <button
                        v-for="cl in clinics" :key="cl.id"
                        @click="clinic = cl.id; loadDoctors(); goTo(6)"
                        class="w-full py-2 bg-gray-100 rounded-lg hover:bg-indigo-100 transition"
                    >
                        {{ cl.name }}
                    </button>
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
                        @click="doctor = d.id; goTo(7)"
                        class="w-full py-2 bg-gray-100 rounded-lg hover:bg-indigo-100 transition"
                    >
                        {{ d.name }}
                    </button>
                </div>
                <button @click="goBack" class="text-sm text-gray-500 hover:text-gray-700">← Назад</button>
            </div>

            <!-- Step 7 -->
            <div v-else-if="step === 7" class="space-y-4">
                <h3 class="text-xl font-semibold text-gray-800">Форма записи</h3>
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
