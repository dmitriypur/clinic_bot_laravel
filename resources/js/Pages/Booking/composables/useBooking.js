import { reactive, computed } from 'vue'
import axios from 'axios'
import { ru } from 'date-fns/locale'

const APP_TIMEZONE = 'Europe/Moscow'
const apiDateFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: APP_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
})

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
    return apiDateFormatter.format(d)
}

const normalizeTelegramId = (value) => {
    if (value === null || value === undefined) {
        return null
    }

    const raw = String(value).trim()

    if (!raw) {
        return null
    }

    if (!/^-?\d+$/.test(raw)) {
        return null
    }

    return raw
}

const sanitizeFullName = (value) => {
    if (!value) {
        return ''
    }

    return value
        .replace(/[^А-Яа-яЁёA-Za-z\s-]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
}

const formatPhoneForState = (value) => {
    if (!value) {
        return ''
    }

    const digitsOnly = String(value).replace(/\D/g, '')

    if (!digitsOnly) {
        return ''
    }

    let digits = digitsOnly

    if (digits.startsWith('8')) {
        digits = `7${digits.slice(1)}`
    } else if (!digits.startsWith('7')) {
        digits = `7${digits}`
    }

    digits = digits.slice(0, 11)

    let formatted = '+7'

    if (digits.length > 1) {
        formatted += ` (${digits.slice(1, 4)}`
    }

    if (digits.length >= 4) {
        formatted += ')'
    }

    if (digits.length > 4) {
        formatted += ` ${digits.slice(4, 7)}`
    }

    if (digits.length > 7) {
        formatted += `-${digits.slice(7, 9)}`
    }

    if (digits.length > 9) {
        formatted += `-${digits.slice(9, 11)}`
    }

    return formatted.trim()
}

const parseTelegramInitDataString = (raw) => {
    if (!raw) {
        return { user: null, chat: null }
    }

    const params = new URLSearchParams(raw)

    let user = null
    let chat = null

    const userRaw = params.get('user')
    if (userRaw) {
        try {
            user = JSON.parse(userRaw)
        } catch (error) {
            console.error('Не удалось распарсить данные пользователя Telegram', error)
        }
    }

    const chatRaw = params.get('chat')
    if (chatRaw) {
        try {
            chat = JSON.parse(chatRaw)
        } catch (error) {
            console.error('Не удалось распарсить данные чата Telegram', error)
        }
    }

    return { user, chat }
}

export function useBooking() {
    const now = new Date()
    const labelFormatter = new Intl.DateTimeFormat('ru-RU', {
        timeZone: APP_TIMEZONE,
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    })
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
        consent: false,
        tgUserId: null,
        tgChatId: null,
        cities: [],
        clinics: [],
        branchesByClinic: {},
        expandedClinicId: null,
        doctors: [],
        selectedDate: now,
        minDate: new Date(now.getTime()),
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
        return labelFormatter.format(date)
    })

    const datepickerLocale = ru

    const resetSchedule = () => {
        const freshDate = new Date()
        state.selectedDate = freshDate
        state.minDate = new Date(freshDate.getTime())
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
            state.consent = false
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
        if (!state.tgChatId && state.tgUserId) {
            state.tgChatId = state.tgUserId
        }

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
            tg_user_id: state.tgUserId,
            tg_chat_id: state.tgChatId,
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
        state.consent = false

        await loadCities()
        initTelegramContext()
    }

    const initTelegramContext = () => {
        if (typeof window === 'undefined') {
            return
        }

        const webApp = window.Telegram?.WebApp ?? null
        const initData = webApp?.initDataUnsafe ?? {}
        const initDataString = webApp?.initData ?? ''

        const webAppUserId = normalizeTelegramId(initData?.user?.id)
        const webAppChatId = normalizeTelegramId(initData?.chat?.id)

        const params = new URLSearchParams(window.location.search || '')
        const paramUserId = normalizeTelegramId(params.get('tg_user_id'))
        const paramChatId = normalizeTelegramId(params.get('tg_chat_id'))
        const paramPhone = params.get('phone') || params.get('tg_phone') || ''

        const hashString = window.location.hash.startsWith('#')
            ? window.location.hash.slice(1)
            : window.location.hash

        const hashParams = new URLSearchParams(hashString)
        const hashTgData = hashParams.get('tgWebAppData') || ''

        const parsedInitData = parseTelegramInitDataString(initDataString)
        const parsedHashData = parseTelegramInitDataString(hashTgData)

        const userCandidate = initData?.user
            ?? parsedInitData.user
            ?? parsedHashData.user
            ?? null

        const chatCandidate = initData?.chat
            ?? parsedInitData.chat
            ?? parsedHashData.chat
            ?? null

        const chatIdFromRaw = normalizeTelegramId(chatCandidate?.id)

        if (!state.tgUserId) {
            const userIdFromRaw = normalizeTelegramId(userCandidate?.id)
            state.tgUserId = webAppUserId ?? userIdFromRaw ?? paramUserId ?? null
        }

        if (!state.tgChatId) {
            const candidateChatId = webAppChatId
                ?? chatIdFromRaw
                ?? paramChatId
                ?? webAppUserId
                ?? paramUserId
                ?? null

            state.tgChatId = candidateChatId
        }

        if (!state.tgChatId && state.tgUserId) {
            state.tgChatId = state.tgUserId
        }

        if (!state.fio) {
            const firstName = userCandidate?.first_name?.trim() ?? ''
            const lastName = userCandidate?.last_name?.trim() ?? ''
            const username = userCandidate?.username?.trim() ?? ''

            const fullNameCandidate = sanitizeFullName(
                [firstName, lastName].filter(Boolean).join(' ') || username,
            )

            if (fullNameCandidate) {
                state.fio = fullNameCandidate
            }
        }

        if (!state.phone && paramPhone) {
            state.phone = formatPhoneForState(paramPhone) || paramPhone
        }

        try {
            webApp?.ready?.()
            webApp?.expand?.()
        } catch (error) {
            console.warn('Telegram WebApp ready/expand вызвали ошибку', error)
        }
    }

    return {
        state,
        progress,
        datepickerLocale,
        hasAvailableSlots,
        selectedDateLabel,
        appTimezone: APP_TIMEZONE,
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
            initTelegramContext,
        },
    }
}
