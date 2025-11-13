import { reactive, computed } from 'vue'
import axios from 'axios'
import { ru } from 'date-fns/locale'

const citiesCache = {
    data: null,
    request: null,
}

const clinicsCache = new Map()
const clinicsRequests = new Map()

const branchesCache = new Map()
const branchesRequests = new Map()

const doctorsCache = new Map()
const doctorsRequests = new Map()

const APP_TIMEZONE = 'Europe/Moscow'
const apiDateFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: APP_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
})

// Шаблоны шагов мастера для разных сценариев
const PROMO_FLOW_STEPS = [1, 2, 5, 8]
const DEFAULT_FLOW_STEPS = [1, 2, 3, 4, 5, 6, 7, 8]

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
    // Единый стор для всей страницы бронирования; изменяется только через экшены ниже
    const state = reactive({
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
        childFio: '',
        phone: '',
        consent: false,
        promoCode: '',
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
        slotValidationError: null,
        isSlotValidationInProgress: false,
    })

    // Флаг «промо» активируется сразу после ввода непустого промокода
    const isPromoFlow = computed(() => Boolean((state.promoCode || '').trim()))
    // Дерево шагов зависит от наличия промокода
    const activeStepsOrder = computed(() => (isPromoFlow.value ? PROMO_FLOW_STEPS : DEFAULT_FLOW_STEPS))
    const currentStepPosition = computed(() => {
        const index = activeStepsOrder.value.indexOf(state.step)
        return index === -1 ? 1 : index + 1
    })
    const totalSteps = computed(() => activeStepsOrder.value.length)
    const progress = computed(() => (totalSteps.value ? (currentStepPosition.value / totalSteps.value) * 100 : 0))
    const hasAvailableSlots = computed(() => state.slots.some((slot) => slot.is_available))
    const selectedDateLabel = computed(() => {
        const date = ensureDateInstance(state.selectedDate) || new Date()
        return labelFormatter.format(date)
    })

    const datepickerLocale = ru

    // При любом изменении врача/клиники очищаем даты и выбранные слоты
    const resetSchedule = () => {
        const freshDate = new Date()
        state.selectedDate = freshDate
        state.minDate = new Date(freshDate.getTime())
        state.selectedSlot = null
        state.slots = []
        state.isLoadingSlots = false
        state.slotValidationError = null
        state.isSlotValidationInProgress = false
    }

    const resetSelectedDoctor = () => {
        state.selectedDoctorId = null
        state.selectedDoctor = null
        resetSchedule()
    }

    // Смена города/режима требует полного сброса клиник и веток
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

    // Переключение шага с сохранением истории для кнопки «Назад»
    const goTo = (stepNumber) => {
        state.step = stepNumber
        state.history.push(stepNumber)
    }

    const cleanupAfterBack = (fromStep, toStep) => {
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

    // Загружаем список городов с примитивным кешированием
    const loadCities = async ({ force = false } = {}) => {
        if (!force && citiesCache.data) {
            state.cities = citiesCache.data.slice()
            return
        }

        if (!force && citiesCache.request) {
            state.isLoadingCities = true
            try {
                const cached = await citiesCache.request
                state.cities = cached.slice()
            } catch (error) {
                console.error('Не удалось загрузить города', error)
                state.cities = []
            } finally {
                state.isLoadingCities = false
            }
            return
        }

        state.isLoadingCities = true
        const request = axios.get('/api/v1/cities')
            .then(({ data }) => (Array.isArray(data.data) ? data.data : []))
        citiesCache.request = request

        try {
            const cities = await request
            citiesCache.data = cities
            state.cities = cities.slice()
        } catch (error) {
            console.error('Не удалось загрузить города', error)
            state.cities = []
        } finally {
            citiesCache.request = null
            state.isLoadingCities = false
        }
    }

    // Список клиник зависит от выбранного города, поэтому кешируем по city_id
    const loadClinics = async ({ force = false } = {}) => {
        if (!state.cityId) {
            state.clinics = []
            return
        }
        const cacheKey = String(state.cityId)

        if (!force && clinicsCache.has(cacheKey)) {
            state.clinics = clinicsCache.get(cacheKey).slice()
            return
        }

        if (!force && clinicsRequests.has(cacheKey)) {
            state.isLoadingClinics = true
            try {
                const cached = await clinicsRequests.get(cacheKey)
                state.clinics = cached.slice()
            } catch (error) {
                console.error('Не удалось загрузить клиники', error)
                state.clinics = []
            } finally {
                state.isLoadingClinics = false
            }
            return
        }
        state.isLoadingClinics = true
        const request = axios.get(`/api/v1/cities/${state.cityId}/clinics`)
            .then(({ data }) => (Array.isArray(data.data) ? data.data : []))
        clinicsRequests.set(cacheKey, request)

        try {
            const clinics = await request
            clinicsCache.set(cacheKey, clinics)
            state.clinics = clinics.slice()
        } catch (error) {
            console.error('Не удалось загрузить клиники', error)
            state.clinics = []
        } finally {
            clinicsRequests.delete(cacheKey)
            state.isLoadingClinics = false
        }
    }

    const loadBranchesForClinic = async (clinicId, { force = false } = {}) => {
        const cacheKey = `${state.cityId || 'null'}::${clinicId}`

        if (!force && branchesCache.has(cacheKey)) {
            state.branchesByClinic = {
                ...state.branchesByClinic,
                [clinicId]: branchesCache.get(cacheKey).slice(),
            }
            return
        }

        if (!force && branchesRequests.has(cacheKey)) {
            state.loadingBranchesId = clinicId
            try {
                const cached = await branchesRequests.get(cacheKey)
                state.branchesByClinic = {
                    ...state.branchesByClinic,
                    [clinicId]: cached.slice(),
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
            return
        }

        state.loadingBranchesId = clinicId
        const request = axios.get(`/api/v1/clinics/${clinicId}/branches`, {
            params: {
                city_id: state.cityId,
            },
        }).then(({ data }) => (Array.isArray(data.data) ? data.data : []))
        branchesRequests.set(cacheKey, request)

        try {
            const branches = await request
            branchesCache.set(cacheKey, branches)
            state.branchesByClinic = {
                ...state.branchesByClinic,
                [clinicId]: branches.slice(),
            }
        } catch (error) {
            console.error('Не удалось загрузить филиалы', error)
            state.branchesByClinic = {
                ...state.branchesByClinic,
                [clinicId]: [],
            }
        } finally {
            branchesRequests.delete(cacheKey)
            state.loadingBranchesId = null
        }
    }

    const resolveDoctorsCacheKey = () => {
        const parts = [
            state.bookingMode || 'none',
            state.cityId || 'none',
            state.clinicId || 'none',
            state.branchId || 'none',
            state.birthDate || 'none',
        ]
        return parts.join('|')
    }

    const loadDoctors = async ({ force = false } = {}) => {
        state.isLoadingDoctors = true
        const params = {}
        if (state.birthDate) {
            params.birth_date = state.birthDate
        }
        if (state.branchId) {
            params.branch_id = state.branchId
        }

        const cacheKey = resolveDoctorsCacheKey()

        try {
            if (!force && doctorsCache.has(cacheKey)) {
                state.doctors = doctorsCache.get(cacheKey).slice()
                return
            }

            if (!force && doctorsRequests.has(cacheKey)) {
                const cached = await doctorsRequests.get(cacheKey)
                state.doctors = cached.slice()
                return
            }

            if (state.bookingMode === 'clinic' && state.clinicId) {
                const request = axios.get(`/api/v1/clinics/${state.clinicId}/doctors`, { params })
                    .then(({ data }) => (Array.isArray(data.data) ? data.data : []))
                doctorsRequests.set(cacheKey, request)
                const doctors = await request
                doctorsCache.set(cacheKey, doctors)
                state.doctors = doctors.slice()
            } else if (state.cityId) {
                const request = axios.get(`/api/v1/cities/${state.cityId}/doctors`, { params })
                    .then(({ data }) => (Array.isArray(data.data) ? data.data : []))
                doctorsRequests.set(cacheKey, request)
                const doctors = await request
                doctorsCache.set(cacheKey, doctors)
                state.doctors = doctors.slice()
            } else {
                state.doctors = []
            }
        } catch (error) {
            console.error('Не удалось загрузить докторов', error)
            state.doctors = []
        } finally {
            doctorsRequests.delete(cacheKey)
            state.isLoadingDoctors = false
        }
    }

    const goToDoctorStepOrConfirmation = async () => {
        goTo(6)
        await loadDoctors()
        if (state.doctors.length === 0) {
            if (state.history[state.history.length - 1] === 6) {
                state.history.pop()
            }
            resetSchedule()
            goTo(8)
        }
    }

    const loadSlots = async () => {
        if (!state.selectedDoctorId) {
            state.slots = []
            state.selectedSlot = null
            return
        }

        state.isLoadingSlots = true
        state.slotValidationError = null
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
        state.slotValidationError = null
        await loadSlots()
    }

    const selectSlot = (slot) => {
        if (!slot?.is_available) {
            return
        }
        state.selectedSlot = slot
        state.slotValidationError = null
        applySlotContext(slot)
    }

    const handleScheduleNext = async () => {
        if (!state.selectedSlot) {
            return
        }

        // Локальный режим – просто переходим дальше
        if (!state.selectedSlot.onec_slot_id) {
            goTo(8)
            return
        }

        state.slotValidationError = null
        state.isSlotValidationInProgress = true

        try {
            await axios.post('/api/v1/applications/check-slot', {
                clinic_id: state.clinicId ?? state.selectedSlot.clinic_id,
                branch_id: state.branchId ?? state.selectedSlot.branch_id,
                doctor_id: state.selectedDoctorId,
                onec_slot_id: state.selectedSlot.onec_slot_id,
            })
            goTo(8)
        } catch (error) {
            const message = error.response?.data?.errors?.onec_slot_id?.[0]
                ?? error.response?.data?.message
                ?? 'Слот только что заняли в 1С. Пожалуйста, выберите другое время.'
            state.slotValidationError = message
            await loadSlots()
        } finally {
            state.isSlotValidationInProgress = false
        }
    }

    const skipSchedule = () => {
        if (state.isLoadingSlots) {
            return
        }
        const canSkip = state.slots.length === 0 || !hasAvailableSlots.value
        if (!canSkip) {
            return
        }
        state.selectedSlot = null
        state.slotValidationError = null
        goTo(8)
    }

    // После заполнения формы на первом шаге переходим к выбору города
    const handleInitialFormSubmit = () => {
        if (state.step !== 1) {
            return
        }
        goTo(2)
        if (!state.cities.length && !state.isLoadingCities) {
            loadCities().catch((error) => {
                console.error('Не удалось обновить список городов', error)
            })
        }
    }

    // После выбора города запускаем соответствующий сценарий (обычный или промо)
    const selectCity = async (cityId) => {
        state.cityId = cityId
        state.bookingMode = isPromoFlow.value ? 'promo' : null
        resetClinicContext()
        resetSelectedDoctor()
        state.clinics = []
        state.doctors = []
        if (isPromoFlow.value) {
            goTo(5)
            await loadClinics()
            if (state.clinics.length === 1) {
                await selectClinicForPromo(state.clinics[0])
            }
        } else {
            goTo(3)
            loadClinics().catch((error) => {
                console.error('Предзагрузка клиник завершилась с ошибкой', error)
            })
        }
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

    // В промо-режиме клиника — последний обязательный шаг перед отправкой
    const selectClinicForPromo = async (clinic) => {
        if (!clinic) {
            return
        }
        state.clinicId = clinic.id
        state.branchId = null
        state.expandedClinicId = null
        state.bookingMode = 'promo'
        resetSelectedDoctor()
        resetSchedule()
        if (state.step === 8) {
            return
        }
        goTo(8)
    }

    const toggleClinic = async (clinic) => {
        if (isPromoFlow.value) {
            await selectClinicForPromo(clinic)
            return
        }
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
            await goToDoctorStepOrConfirmation()
        } else if (clinicBranches.length === 1) {
            state.branchId = clinicBranches[0].id
            await goToDoctorStepOrConfirmation()
        }
    }

    const selectBranch = async ({ clinicId, branch }) => {
        if (isPromoFlow.value) {
            return
        }
        state.clinicId = clinicId
        state.branchId = branch.id
        resetSelectedDoctor()
        await goToDoctorStepOrConfirmation()
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

    // Финальная отправка заявки в API
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
            onec_slot_id: state.selectedSlot ? state.selectedSlot.onec_slot_id : null,
            appointment_datetime: state.selectedSlot ? state.selectedSlot.datetime : null,
            full_name_parent: (state.fio || '').trim(),
            full_name: (state.childFio || '').trim(),
            phone: state.phone,
            birth_date: state.birthDate,
            tg_user_id: state.tgUserId,
            tg_chat_id: state.tgChatId,
            promo_code: (state.promoCode || '').trim() || null,
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
        state.childFio = ''
        state.phone = ''
        state.consent = false
        state.promoCode = ''

        await loadCities({ force: true })
        initTelegramContext()
    }

    // Подтягиваем данные из Telegram WebApp чтобы максимально авто-заполнить форму
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

    // Экспортируем состояние, вычисления и экшены для страницы
    return {
        state,
        progress,
        datepickerLocale,
        hasAvailableSlots,
        selectedDateLabel,
        appTimezone: APP_TIMEZONE,
        currentStepPosition,
        totalSteps,
        isPromoFlow,
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
            handleScheduleNext,
            skipSchedule,
            selectCity,
            setBirthDate,
            onDateChange,
            submit,
            initTelegramContext,
            selectClinicForPromo,
            handleInitialFormSubmit,
        },
    }
}
