import { reactive, computed } from 'vue'
import axios from 'axios'
import { ru } from 'date-fns/locale'

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

export function useBooking() {
    const now = new Date()
    const state = reactive({
        totalSteps: 8,
        step: 1,
        history: [1],
        bookingMode: null,
        cityId: null,
        birthDate: null,
        clinicId: null,
        branchId: null,
        selectedDoctorId: null,
        selectedDoctor: null,
        fio: '',
        phone: '',
        cities: [],
        clinics: [],
        branchesByClinic: {},
        expandedClinicId: null,
        doctors: [],
        selectedDate: now,
        minDate: now,
        slots: [],
        selectedSlot: null,
        isLoadingCities: false,
        isLoadingClinics: false,
        isLoadingDoctors: false,
        isLoadingSlots: false,
        loadingBranchesId: null,
    })

    const progress = computed(() => (state.step / state.totalSteps) * 100)
    const hasAvailableSlots = computed(() => state.slots.some((slot) => slot.is_available))
    const selectedDateLabel = computed(() => {
        const date = ensureDateInstance(state.selectedDate) || new Date()
        return date.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
        })
    })

    const datepickerLocale = ru

    const resetSchedule = () => {
        const freshDate = new Date()
        state.selectedDate = freshDate
        state.minDate = freshDate
        state.selectedSlot = null
        state.slots = []
        state.isLoadingSlots = false
    }

    const resetSelectedDoctor = () => {
        state.selectedDoctorId = null
        state.selectedDoctor = null
        resetSchedule()
    }

    const resetClinicContext = () => {
        state.clinicId = null
        state.branchId = null
        state.expandedClinicId = null
        state.branchesByClinic = {}
    }

    const resetHistory = () => {
        state.history.splice(0, state.history.length, 1)
    }

    const applySlotContext = (slot) => {
        if (!slot) {
            return
        }
        const clinicIdFromSlot = slot.clinic_id ?? slot.clinic?.id ?? null
        const branchIdFromSlot = slot.branch_id ?? slot.branch?.id ?? null

        if (clinicIdFromSlot) {
            state.clinicId = clinicIdFromSlot
        }
        if (branchIdFromSlot) {
            state.branchId = branchIdFromSlot
        }
    }

    const goTo = (stepNumber) => {
        state.step = stepNumber
        state.history.push(stepNumber)
    }

    const cleanupAfterBack = (fromStep, toStep) => {
        if (fromStep >= 8 && toStep <= 7) {
            state.fio = ''
            state.phone = ''
        }

        if (fromStep >= 7 && toStep <= 6) {
            resetSchedule()
        }

        if (fromStep >= 6 && toStep <= 5) {
            resetSelectedDoctor()
        }

        if (fromStep >= 5 && toStep <= 4) {
            resetClinicContext()
            state.clinics = []
        }

        if (fromStep >= 4 && toStep <= 3) {
            state.bookingMode = null
        }
    }

    const goBack = () => {
        if (state.history.length <= 1) {
            return
        }
        const currentStep = state.step
        state.history.pop()
        const previousStep = state.history[state.history.length - 1]
        cleanupAfterBack(currentStep, previousStep)
        state.step = previousStep
    }

    const loadCities = async () => {
        state.isLoadingCities = true
        try {
            const { data } = await axios.get('/api/v1/cities')
            state.cities = Array.isArray(data.data) ? data.data : []
        } catch (error) {
            console.error('Не удалось загрузить города', error)
            state.cities = []
        } finally {
            state.isLoadingCities = false
        }
    }

    const loadClinics = async () => {
        if (!state.cityId) {
            state.clinics = []
            return
        }
        state.isLoadingClinics = true
        try {
            const { data } = await axios.get(`/api/v1/cities/${state.cityId}/clinics`)
            state.clinics = Array.isArray(data.data) ? data.data : []
        } catch (error) {
            console.error('Не удалось загрузить клиники', error)
            state.clinics = []
        } finally {
            state.isLoadingClinics = false
        }
    }

    const loadBranchesForClinic = async (clinicId) => {
        state.loadingBranchesId = clinicId
        try {
            const { data } = await axios.get(`/api/v1/clinics/${clinicId}/branches`, {
                params: {
                    city_id: state.cityId,
                },
            })
            state.branchesByClinic = {
                ...state.branchesByClinic,
                [clinicId]: Array.isArray(data.data) ? data.data : [],
            }
        } catch (error) {
            console.error('Не удалось загрузить филиалы', error)
            state.branchesByClinic = {
                ...state.branchesByClinic,
                [clinicId]: [],
            }
        } finally {
            state.loadingBranchesId = null
        }
    }

    const loadDoctors = async () => {
        state.isLoadingDoctors = true
        try {
            const params = {}
            if (state.birthDate) {
                params.birth_date = state.birthDate
            }
            if (state.branchId) {
                params.branch_id = state.branchId
            }

            if (state.bookingMode === 'clinic' && state.clinicId) {
                const { data } = await axios.get(`/api/v1/clinics/${state.clinicId}/doctors`, { params })
                state.doctors = Array.isArray(data.data) ? data.data : []
            } else if (state.cityId) {
                const { data } = await axios.get(`/api/v1/cities/${state.cityId}/doctors`, { params })
                state.doctors = Array.isArray(data.data) ? data.data : []
            } else {
                state.doctors = []
            }
        } catch (error) {
            console.error('Не удалось загрузить докторов', error)
            state.doctors = []
        } finally {
            state.isLoadingDoctors = false
        }
    }

    const loadSlots = async () => {
        if (!state.selectedDoctorId) {
            state.slots = []
            state.selectedSlot = null
            return
        }

        state.isLoadingSlots = true
        const previouslySelected = state.selectedSlot ? state.selectedSlot.datetime : null
        state.selectedSlot = null

        try {
            const { data } = await axios.get(`/api/v1/doctors/${state.selectedDoctorId}/slots`, {
                params: {
                    date: formatDateForApi(state.selectedDate),
                    clinic_id: state.clinicId || undefined,
                    branch_id: state.branchId || undefined,
                },
            })

            const slotData = Array.isArray(data.data) ? data.data : []
            state.slots = slotData

            if (previouslySelected) {
                const matchedSlot = slotData.find(
                    (slot) => slot.datetime === previouslySelected && slot.is_available,
                )
                if (matchedSlot) {
                    state.selectedSlot = matchedSlot
                }
            }
            if (!state.selectedSlot) {
                const firstAvailable = slotData.find((slot) => slot.is_available)
                state.selectedSlot = firstAvailable || null
            }
            applySlotContext(state.selectedSlot)
        } catch (error) {
            console.error('Не удалось загрузить слоты', error)
            state.slots = []
        } finally {
            state.isLoadingSlots = false
        }
    }

    const onDateChange = async (value) => {
        state.selectedDate = ensureDateInstance(value) || new Date()
        await loadSlots()
    }

    const selectSlot = (slot) => {
        if (!slot?.is_available) {
            return
        }
        state.selectedSlot = slot
        applySlotContext(slot)
    }

    const selectCity = async (cityId) => {
        state.cityId = cityId
        state.bookingMode = null
        resetClinicContext()
        resetSelectedDoctor()
        state.clinics = []
        state.doctors = []
        goTo(3)
    }

    const setBirthDate = (value) => {
        state.birthDate = value || null
    }

    const startClinicFlow = async () => {
        state.bookingMode = 'clinic'
        resetClinicContext()
        resetSelectedDoctor()
        goTo(5)
        await loadClinics()
    }

    const startDoctorFlow = async () => {
        state.bookingMode = 'doctor'
        resetClinicContext()
        resetSelectedDoctor()
        goTo(6)
        await loadDoctors()
    }

    const toggleClinic = async (clinic) => {
        if (state.expandedClinicId === clinic.id) {
            state.expandedClinicId = null
            state.clinicId = null
            state.branchId = null
            resetSelectedDoctor()
            return
        }

        state.clinicId = clinic.id
        state.branchId = null
        state.expandedClinicId = clinic.id
        resetSelectedDoctor()

        if (!state.branchesByClinic[clinic.id]) {
            await loadBranchesForClinic(clinic.id)
        }

        const clinicBranches = state.branchesByClinic[clinic.id] || []
        if (clinicBranches.length === 0) {
            goTo(6)
            await loadDoctors()
        } else if (clinicBranches.length === 1) {
            state.branchId = clinicBranches[0].id
            goTo(6)
            await loadDoctors()
        }
    }

    const selectBranch = async ({ clinicId, branch }) => {
        state.clinicId = clinicId
        state.branchId = branch.id
        resetSelectedDoctor()
        goTo(6)
        await loadDoctors()
    }

    const selectDoctor = async (doctor) => {
        state.selectedDoctorId = doctor.id
        state.selectedDoctor = doctor
        if (state.bookingMode === 'doctor') {
            state.clinicId = null
            state.branchId = null
        }
        resetSchedule()
        goTo(7)
        await loadSlots()
    }

    const submit = async () => {
        await axios.post('/api/v1/applications', {
            city_id: state.cityId,
            clinic_id: state.clinicId,
            branch_id: state.branchId,
            doctor_id: state.selectedDoctorId,
            cabinet_id: state.selectedSlot ? state.selectedSlot.cabinet_id : null,
            appointment_datetime: state.selectedSlot ? state.selectedSlot.datetime : null,
            full_name: state.fio,
            phone: state.phone,
            birth_date: state.birthDate,
        })

        alert('Вы успешно записаны!')

        state.step = 1
        resetHistory()
        state.bookingMode = null
        state.cityId = null
        state.birthDate = null
        resetClinicContext()
        resetSelectedDoctor()
        state.clinics = []
        state.doctors = []
        state.cities = []
        state.fio = ''
        state.phone = ''

        await loadCities()
    }

    return {
        state,
        progress,
        datepickerLocale,
        hasAvailableSlots,
        selectedDateLabel,
        actions: {
            goTo,
            goBack,
            loadCities,
            loadClinics,
            startClinicFlow,
            startDoctorFlow,
            toggleClinic,
            selectBranch,
            selectDoctor,
            selectSlot,
            selectCity,
            setBirthDate,
            onDateChange,
            submit,
        },
    }
}
